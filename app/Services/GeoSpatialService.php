<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;

class GeoSpatialService
{
    public const LEVELS = ['kec', 'desa', 'sls', 'subsls'];

    public const METRICS = ['progress', 'submitted', 'approved', 'rejected', 'open', 'assignment', 'priority', 'coverage'];

    private const STATUS_COLUMNS = [
        'OPEN',
        'DRAFT',
        'SUBMITTED BY Pencacah',
        'APPROVED BY Pengawas',
        'REJECTED BY Pengawas',
        'EDITED BY Pengawas',
        'REVOKED BY Pengawas',
        'SUBMITTED RESPONDENT',
    ];

    private const SAFE_PROPERTIES = [
        'kdprov', 'kdkab', 'kdkec', 'kddesa', 'kdsls', 'kdsubsls',
        'idkab', 'idkec', 'iddesa', 'idsls', 'idsubsls',
        'nmprov', 'nmkab', 'nmkec', 'nmdesa', 'nmsls', 'subsls',
        'periode', 'luas',
    ];

    public function sourcePath(): string
    {
        return (string) config('geo.source');
    }

    public function preparedPath(): string
    {
        return (string) config('geo.prepared');
    }

    /** @return array{path: string, report: array<string, mixed>} */
    public function prepare(): array
    {
        $source = $this->sourcePath();
        $prepared = $this->preparedPath();

        if (! File::exists($source)) {
            throw new RuntimeException('File GeoJSON belum tersedia.');
        }

        if (File::exists($prepared) && File::lastModified($prepared) >= File::lastModified($source)) {
            return ['path' => $prepared, 'report' => $this->validationReport($prepared)];
        }

        try {
            $geojson = json_decode(File::get($source), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('GeoJSON tidak valid: '.$exception->getMessage(), previous: $exception);
        }

        if (($geojson['type'] ?? null) !== 'FeatureCollection' || ! is_array($geojson['features'] ?? null)) {
            throw new RuntimeException('GeoJSON harus berupa FeatureCollection.');
        }

        $features = [];
        $invalid = [];
        $seen = [];
        $duplicates = [];

        foreach ($geojson['features'] as $index => $feature) {
            $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $geometry = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : [];
            $id = trim((string) ($properties['idsubsls'] ?? ''));
            $geometryType = $geometry['type'] ?? null;

            if ($id === '' || ! in_array($geometryType, ['Polygon', 'MultiPolygon'], true) || empty($geometry['coordinates'])) {
                $invalid[] = ['index' => $index, 'idsubsls' => $id ?: null];

                continue;
            }

            if (isset($seen[$id])) {
                $duplicates[] = $id;

                continue;
            }

            $seen[$id] = true;
            $safe = [];
            foreach (self::SAFE_PROPERTIES as $key) {
                if (array_key_exists($key, $properties)) {
                    $safe[$key] = $properties[$key];
                }
            }

            $features[] = [
                'type' => 'Feature',
                'id' => $id,
                'properties' => $safe,
                'geometry' => $geometry,
            ];
        }

        $sanitized = [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
        $directory = dirname($prepared);
        File::ensureDirectoryExists($directory);
        $temporary = $prepared.'.tmp';
        File::put($temporary, json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        File::move($temporary, $prepared);

        return [
            'path' => $prepared,
            'report' => [
                'feature_count' => count($features),
                'invalid_features' => $invalid,
                'duplicate_ids' => array_values(array_unique($duplicates)),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function coverage(string $role = 'pengawas'): array
    {
        $prepared = $this->prepare();
        $geojson = json_decode(File::get($prepared['path']), true, 512, JSON_THROW_ON_ERROR);
        $geoIds = collect($geojson['features'])->pluck('properties.idsubsls')->filter()->map(fn ($id) => (string) $id)->unique();
        $table = $this->tableForRole($role);
        $dbIds = DB::connection('fasih')->table($table)
            ->whereNotNull('idsubsls')
            ->where('idsubsls', '<>', '')
            ->distinct()
            ->pluck('idsubsls')
            ->map(fn ($id) => (string) $id)
            ->unique();
        $sentinels = $dbIds->filter(fn (string $id) => str_ends_with($id, '000000000000'))->values();
        $realDbIds = $dbIds->diff($sentinels);
        $matched = $geoIds->intersect($realDbIds);

        return [
            ...$prepared['report'],
            'matched' => $matched->count(),
            'geojson_only' => $geoIds->diff($realDbIds)->values()->all(),
            'database_only' => $realDbIds->diff($geoIds)->values()->all(),
            'database_sentinels' => $sentinels->all(),
            'coverage_pct' => $realDbIds->isEmpty() ? 100 : round($matched->count() / $realDbIds->count() * 100, 2),
        ];
    }

    /** @return array<string, mixed> */
    public function metrics(string $snapshot, string $role, string $level, string $metric, ?string $parentId = null, ?string $compareSnapshot = null): array
    {
        $table = $this->tableForRole($role);
        $rows = $this->aggregate($table, $snapshot, $level, $parentId);
        $comparison = $compareSnapshot ? $this->aggregate($table, $compareSnapshot, $level, $parentId)->keyBy('key') : collect();

        $items = $rows->mapWithKeys(function (object $row) use ($comparison, $metric) {
            $item = $this->metricRow($row);
            $compare = $comparison->get($item['key']);
            $compareItem = $compare ? $this->metricRow($compare) : null;
            $value = $this->metricValue($item, $metric);
            $compareValue = $compareItem ? $this->metricValue($compareItem, $metric) : null;

            return [$item['key'] => [
                ...$item,
                'value' => $value,
                'delta' => $compareValue === null ? null : round($value - $compareValue, 1),
            ]];
        });

        return [
            'snapshot' => $snapshot,
            'compare_snapshot' => $compareSnapshot,
            'role' => $role,
            'level' => $level,
            'metric' => $metric,
            'parent_id' => $parentId,
            'items' => $items,
            'quality' => $this->coverage($role),
        ];
    }

    /** @return array<string, mixed>|null */
    public function region(string $id, string $snapshot, string $role): ?array
    {
        $table = $this->tableForRole($role);
        $query = DB::connection('fasih')->table($table)->where('snapshot_at', $snapshot)->where('idsubsls', $id);
        $row = (clone $query)->selectRaw($this->aggregateSql("idsubsls as key, COALESCE(NULLIF(nmsubsls, ''), NULLIF(nmsls, ''), idsubsls) as label"))
            ->groupBy('idsubsls', 'nmsubsls', 'nmsls')
            ->first();

        if (! $row) {
            return null;
        }

        $petugas = (clone $query)->select('username', 'nama_lengkap')
            ->whereNotNull('username')
            ->distinct()
            ->orderBy('nama_lengkap')
            ->get()
            ->map(fn ($person) => [
                'username' => $person->username,
                'name' => str($person->nama_lengkap ?: $person->username)->lower()->title()->toString(),
            ]);
        $trendSnapshots = DB::connection('fasih')->table($table)
            ->where('idsubsls', $id)
            ->select('snapshot_at')
            ->distinct()
            ->orderByDesc('snapshot_at')
            ->limit(7)
            ->pluck('snapshot_at')
            ->sort()
            ->values();
        $trend = DB::connection('fasih')->table($table)
            ->where('idsubsls', $id)
            ->whereIn('snapshot_at', $trendSnapshots)
            ->selectRaw($this->aggregateSql('snapshot_at as key, snapshot_at as label'))
            ->groupBy('snapshot_at')
            ->orderBy('snapshot_at')
            ->get()
            ->map(fn ($point) => ['snapshot_at' => $point->key, ...$this->metricRow($point)]);

        return [
            ...$this->metricRow($row),
            'petugas' => $petugas,
            'trend' => $trend,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function officers(string $type, ?string $scopeId = null): array
    {
        $column = $type === 'pengawas' ? 'pengawas_user_id' : 'pencacah_user_id';
        $userIdSql = $this->normalizedIdSql('u.user_id');
        $names = $this->progressNames($type);
        $query = DB::connection('fasih')->table('petugas_wilayah as pw')
            ->join('users as u', 'u.user_id', '=', "pw.{$column}")
            ->whereNotNull("pw.{$column}");

        if ($scopeId) {
            $query->where('pw.idsubsls', 'like', $scopeId.'%');
        }

        return $query->selectRaw("{$userIdSql} as id, u.fullname as name, COUNT(DISTINCT pw.idsubsls) as region_count")
            ->groupByRaw($userIdSql.', u.fullname')
            ->orderBy('u.fullname')
            ->get()
            ->map(fn (object $officer) => [
                'id' => (string) $officer->id,
                'name' => str($officer->name ?: $names[(string) $officer->id] ?? 'Tanpa nama')->lower()->title()->toString(),
                'type' => $type,
                'region_count' => (int) $officer->region_count,
            ])->all();
    }

    /** @return array<string, mixed>|null */
    public function officerRegions(string $userId, string $type): ?array
    {
        $column = $type === 'pengawas' ? 'pengawas_user_id' : 'pencacah_user_id';
        $usersIdSql = $this->normalizedIdSql('user_id');
        $assignmentIdSql = $this->normalizedIdSql($column);
        $officer = DB::connection('fasih')->table('users')
            ->whereRaw("{$usersIdSql} = ?", [$userId])
            ->selectRaw("{$usersIdSql} as id, fullname")
            ->first();

        if (! $officer) {
            return null;
        }

        $regionIds = DB::connection('fasih')->table('petugas_wilayah')
            ->whereRaw("{$assignmentIdSql} = ?", [$userId])
            ->whereNotNull('idsubsls')
            ->distinct()
            ->orderBy('idsubsls')
            ->pluck('idsubsls')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();

        return [
            'id' => (string) $officer->id,
            'name' => str($officer->fullname ?: $this->progressNames($type)[$userId] ?? 'Tanpa nama')->lower()->title()->toString(),
            'type' => $type,
            'region_count' => count($regionIds),
            'region_ids' => $regionIds,
        ];
    }

    /** @return array<string, mixed>|null */
    public function regionDetail(string $level, string $id, string $snapshot): ?array
    {
        $labelColumn = match ($level) {
            'kec' => 'nmkec',
            'desa' => 'nmdesa',
            'sls' => 'nmsls',
            default => 'nmsubsls',
        };
        $query = DB::connection('fasih')->table('progress_pencacah')
            ->where('snapshot_at', $snapshot);
        $this->applyRegionScope($query, $level, $id);
        $row = (clone $query)->selectRaw($this->aggregateSql("? as key, COALESCE(MIN(NULLIF({$labelColumn}, '')), ?) as label"), [$id, $id])->first();

        if (! $row || (int) ($row->total ?? 0) === 0) {
            return null;
        }

        $pmlByPpl = $this->pmlByPpl($level, $id);
        $pencacah = (clone $query)
            ->whereNotNull('user_id')
            ->selectRaw($this->aggregateSql("user_id as key, COALESCE(NULLIF(nama_lengkap, ''), user_id) as label"))
            ->groupBy('user_id', 'nama_lengkap')
            ->orderBy('nama_lengkap')
            ->get()
            ->map(function (object $person) use ($pmlByPpl) {
                $metrics = $this->metricRow($person);
                $name = (string) $person->label === (string) $person->key
                    ? 'Petugas tanpa nama'
                    : str($person->label)->lower()->title()->toString();

                return [
                    'id' => (string) $person->key,
                    'name' => $name,
                    ...$metrics,
                    'pml' => $pmlByPpl[(string) $person->key] ?? [],
                ];
            })->all();

        return [
            ...$this->metricRow($row),
            'level' => $level,
            'pencacah' => $pencacah,
            'next_level' => match ($level) {
                'kec' => 'desa',
                'desa' => 'sls',
                'sls' => 'subsls',
                default => null,
            },
        ];
    }

    /** @return array<string, array<int, array{id: string, name: string}>> */
    private function pmlByPpl(string $level, string $id): array
    {
        $pplIdSql = $this->normalizedIdSql('pw.pencacah_user_id');
        $pmlIdSql = $this->normalizedIdSql('pml.user_id');
        $pmlNames = $this->progressNames('pengawas');
        $query = DB::connection('fasih')->table('petugas_wilayah as pw')
            ->leftJoin('users as pml', 'pml.user_id', '=', 'pw.pengawas_user_id')
            ->whereNotNull('pw.pencacah_user_id');
        $this->applyRegionScope($query, $level, $id, 'pw.idsubsls');

        return $query->selectRaw("{$pplIdSql} as pencacah_user_id, {$pmlIdSql} as pml_id, pml.fullname as pml_name")
            ->distinct()
            ->get()
            ->groupBy(fn (object $row) => (string) $row->pencacah_user_id)
            ->map(fn ($rows) => $rows->filter(fn (object $row) => $row->pml_id)->map(fn (object $row) => [
                'id' => (string) $row->pml_id,
                'name' => str($row->pml_name ?: $pmlNames[(string) $row->pml_id] ?? 'Tanpa nama')->lower()->title()->toString(),
            ])->unique('id')->values()->all())
            ->all();
    }

    private function applyRegionScope(Builder $query, string $level, string $id, string $column = 'idsubsls'): void
    {
        if ($level === 'subsls') {
            $query->where($column, $id);

            return;
        }

        $query->where($column, 'like', $id.'%');
    }

    private function normalizedIdSql(string $column): string
    {
        return "CASE WHEN typeof({$column}) = 'blob' THEN lower(substr(hex({$column}), 1, 8) || '-' || substr(hex({$column}), 9, 4) || '-' || substr(hex({$column}), 13, 4) || '-' || substr(hex({$column}), 17, 4) || '-' || substr(hex({$column}), 21, 12)) ELSE CAST({$column} AS TEXT) END";
    }

    /** @return array<string, string> */
    private function progressNames(string $type): array
    {
        $table = $type === 'pengawas' ? 'progress_pengawas' : 'progress_pencacah';

        return DB::connection('fasih')->table($table)
            ->whereNotNull('user_id')
            ->whereNotNull('nama_lengkap')
            ->where('nama_lengkap', '<>', '')
            ->select('user_id', 'nama_lengkap')
            ->distinct()
            ->get()
            ->mapWithKeys(fn (object $row) => [(string) $row->user_id => (string) $row->nama_lengkap])
            ->all();
    }

    private function tableForRole(string $role): string
    {
        return $role === 'pencacah' ? 'progress_pencacah' : 'progress_pengawas';
    }

    private function aggregate(string $table, string $snapshot, string $level, ?string $parentId)
    {
        [$keySql, $labelSql, $groupColumns] = match ($level) {
            'desa' => ['kdprov || kdkab || kdkec || kddes', 'nmdesa', ['kdprov', 'kdkab', 'kdkec', 'kddes', 'nmdesa']],
            'sls' => ['kdprov || kdkab || kdkec || kddes || kdsls', 'nmsls', ['kdprov', 'kdkab', 'kdkec', 'kddes', 'kdsls', 'nmsls']],
            'subsls' => ['idsubsls', "COALESCE(NULLIF(nmsubsls, ''), NULLIF(nmsls, ''), idsubsls)", ['idsubsls', 'nmsubsls', 'nmsls']],
            default => ['kdprov || kdkab || kdkec', 'nmkec', ['kdprov', 'kdkab', 'kdkec', 'nmkec']],
        };
        $query = DB::connection('fasih')->table($table)->where('snapshot_at', $snapshot);
        $this->applyParent($query, $level, $parentId);

        return $query->selectRaw($this->aggregateSql("{$keySql} as key, {$labelSql} as label"))
            ->groupBy($groupColumns)
            ->get();
    }

    private function applyParent(Builder $query, string $level, ?string $parentId): void
    {
        if (! $parentId) {
            return;
        }

        $expression = match ($level) {
            'desa' => 'kdprov || kdkab || kdkec',
            'sls' => 'kdprov || kdkab || kdkec || kddes',
            'subsls' => 'kdprov || kdkab || kdkec || kddes || kdsls',
            default => null,
        };

        if ($expression) {
            $query->whereRaw("{$expression} = ?", [$parentId]);
        }
    }

    private function aggregateSql(string $prefix): string
    {
        $statuses = collect(self::STATUS_COLUMNS)
            ->map(fn (string $column) => "SUM(\"{$column}\") as \"{$column}\"")
            ->implode(', ');

        return "{$prefix}, SUM(region_total) as total, COUNT(DISTINCT username) as petugas, {$statuses}";
    }

    /** @return array<string, mixed> */
    private function metricRow(object $row): array
    {
        $total = max(1, (int) ($row->total ?? 0));
        $open = (int) ($row->OPEN ?? 0);
        $draft = (int) ($row->DRAFT ?? 0);
        $submitted = (int) ($row->{'SUBMITTED BY Pencacah'} ?? 0);
        $approved = (int) ($row->{'APPROVED BY Pengawas'} ?? 0);
        $rejected = (int) ($row->{'REJECTED BY Pengawas'} ?? 0);
        $progress = round(($total - $open - $draft) / $total * 100, 1);
        $rejectedPct = round($rejected / $total * 100, 1);
        $openPct = round($open / $total * 100, 1);

        return [
            'key' => (string) $row->key,
            'label' => (string) ($row->label ?: $row->key),
            'total' => (int) ($row->total ?? 0),
            'petugas' => (int) ($row->petugas ?? 0),
            'progress' => $progress,
            'submitted' => round($submitted / $total * 100, 1),
            'approved' => round($approved / $total * 100, 1),
            'rejected' => $rejectedPct,
            'open' => $openPct,
            'priority' => round((100 - $progress) * .7 + $rejectedPct * .2 + $openPct * .1, 1),
            'statuses' => collect(self::STATUS_COLUMNS)->mapWithKeys(fn (string $column) => [$column => (int) ($row->{$column} ?? 0)]),
        ];
    }

    private function metricValue(array $item, string $metric): float
    {
        return (float) match ($metric) {
            'assignment' => $item['total'],
            'coverage' => $item['total'] > 0 ? 1 : 0,
            default => $item[$metric] ?? $item['progress'],
        };
    }

    /** @return array<string, mixed> */
    private function validationReport(string $path): array
    {
        $geojson = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        return [
            'feature_count' => count($geojson['features'] ?? []),
            'invalid_features' => [],
            'duplicate_ids' => [],
        ];
    }
}
