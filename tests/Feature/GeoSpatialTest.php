<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GeoSpatialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GeoSpatialTest extends TestCase
{
    use RefreshDatabase;

    private string $fasihDbPath;

    private string $geojsonPath;

    private string $preparedPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fasihDbPath = tempnam(sys_get_temp_dir(), 'fasih-geo-db-');
        $this->geojsonPath = tempnam(sys_get_temp_dir(), 'fasih-geo-json-');
        $this->preparedPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fasih-prepared-'.uniqid().'.json';
        config()->set('database.connections.fasih.database', $this->fasihDbPath);
        config()->set('database.connections.fasih.read_only', false);
        config()->set('geo.source', $this->geojsonPath);
        config()->set('geo.prepared', $this->preparedPath);
        DB::purge('fasih');

        DB::connection('fasih')->statement($this->tableSql('progress_pengawas'));
        DB::connection('fasih')->statement($this->tableSql('progress_pencacah'));
        DB::connection('fasih')->statement('CREATE TABLE users (user_id TEXT, email TEXT, fullname TEXT, role TEXT, is_pencacah INTEGER)');
        DB::connection('fasih')->statement('CREATE TABLE petugas_wilayah (pencacah_user_id TEXT, pengawas_user_id TEXT, idsubsls TEXT)');
        $this->writeGeoJson();
        $this->insertProgressRows();
        $this->insertOfficerRows();
    }

    protected function tearDown(): void
    {
        DB::disconnect('fasih');
        foreach ([$this->fasihDbPath, $this->geojsonPath, $this->preparedPath] as $path) {
            if (isset($path) && file_exists($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_geojson_is_sanitized_and_sentinel_is_reported_separately(): void
    {
        $service = app(GeoSpatialService::class);
        $prepared = $service->prepare();
        $geojson = json_decode(file_get_contents($prepared['path']), true, 512, JSON_THROW_ON_ERROR);
        $properties = $geojson['features'][0]['properties'];
        $coverage = $service->coverage();

        $this->assertCount(2, $geojson['features']);
        $this->assertArrayNotHasKey('path', $properties);
        $this->assertArrayNotHasKey('email', $properties);
        $this->assertSame(2, $coverage['matched']);
        $this->assertSame(100.0, $coverage['coverage_pct']);
        $this->assertSame(['7316000000000000'], $coverage['database_sentinels']);
        $this->assertSame([], $coverage['database_only']);
    }

    public function test_metrics_are_aggregated_by_region_and_support_comparison(): void
    {
        $metrics = app(GeoSpatialService::class)->metrics(
            '2026-07-08T08:00:00+08:00',
            'pengawas',
            'kec',
            'progress',
            compareSnapshot: '2026-07-07T08:00:00+08:00',
        );
        $item = $metrics['items']['7316010'];

        $this->assertSame(200, $item['total']);
        $this->assertSame(70.0, $item['progress']);
        $this->assertSame(20.0, $item['delta']);
        $this->assertSame(3, $item['petugas']);
    }

    public function test_geo_endpoints_require_authentication(): void
    {
        $this->getJson('/api/geo/boundaries')->assertUnauthorized();
        $this->getJson('/api/geo/metrics')->assertUnauthorized();
    }

    public function test_boundaries_endpoint_never_exposes_internal_properties(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $response = $this->actingAs($user)->get('/api/geo/boundaries');

        $response->assertOk()->assertHeader('Content-Type', 'application/geo+json; charset=UTF-8');
        $response->assertHeader('ETag');
        $this->assertStringNotContainsString('private/source.geojson', $response->getContent());
        $this->assertStringNotContainsString('operator@example.test', $response->getContent());
    }

    public function test_missing_geojson_returns_a_clear_empty_state_response(): void
    {
        @unlink($this->geojsonPath);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->getJson('/api/geo/boundaries')
            ->assertNotFound()
            ->assertJson(['message' => 'File GeoJSON belum tersedia.']);
    }

    public function test_officer_endpoints_return_scoped_assignments_without_sensitive_fields(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->getJson('/api/geo/officers?type=pencacah&scope_id=7316010018')
            ->assertOk()
            ->assertJsonPath('items.0.name', 'Pencacah Satu')
            ->assertJsonPath('items.0.region_count', 2)
            ->assertJsonMissing(['email' => 'ppl@example.test']);

        $this->actingAs($user)
            ->getJson('/api/geo/officers/ppl-1/regions?type=pencacah')
            ->assertOk()
            ->assertJsonCount(2, 'region_ids')
            ->assertJsonMissingPath('email');
    }

    public function test_generic_region_detail_aggregates_statuses_and_maps_ppl_to_pml(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->getJson('/api/geo/regions/desa/7316010018?snapshot=2026-07-08T08%3A00%3A00%2B08%3A00');

        $response->assertOk()
            ->assertJsonPath('level', 'desa')
            ->assertJsonPath('next_level', 'sls')
            ->assertJsonPath('total', 200)
            ->assertJsonPath('statuses.OPEN', 40)
            ->assertJsonPath('statuses.COMPLETED BY Admin Kabupaten', 4)
            ->assertJsonPath('pencacah.0.name', 'Pencacah Satu')
            ->assertJsonPath('pencacah.0.pml.0.name', 'Pengawas Satu')
            ->assertJsonMissing(['email' => 'pml@example.test']);
    }

    public function test_new_geo_endpoints_validate_authentication_and_officer_type(): void
    {
        $this->getJson('/api/geo/officers?type=pencacah')->assertUnauthorized();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user)->getJson('/api/geo/officers?type=invalid')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/geo/regions/invalid/code?snapshot=test')->assertUnprocessable();
    }

    private function tableSql(string $table): string
    {
        return <<<SQL
            CREATE TABLE {$table} (
                snapshot_at TEXT NOT NULL,
                user_id TEXT,
                username TEXT,
                nama_lengkap TEXT,
                idsubsls TEXT,
                kdprov TEXT,
                kdkab TEXT,
                kdkec TEXT,
                kddes TEXT,
                kdsls TEXT,
                kdsubsls TEXT,
                nmkec TEXT,
                nmdesa TEXT,
                nmsls TEXT,
                nmsubsls TEXT,
                region_total INTEGER NOT NULL,
                "OPEN" INTEGER NOT NULL,
                "DRAFT" INTEGER NOT NULL,
                "SUBMITTED BY Pencacah" INTEGER NOT NULL,
                "APPROVED BY Pengawas" INTEGER NOT NULL,
                "REJECTED BY Pengawas" INTEGER NOT NULL,
                "EDITED BY Pengawas" INTEGER NOT NULL,
                "REVOKED BY Pengawas" INTEGER NOT NULL,
                "SUBMITTED RESPONDENT" INTEGER NOT NULL,
                "COMPLETED BY Admin Kabupaten" INTEGER NOT NULL,
                "EDITED BY Admin Kabupaten" INTEGER NOT NULL,
                "REJECTED BY Admin Kabupaten" INTEGER NOT NULL,
                "REVOKED BY Admin Kabupaten" INTEGER NOT NULL
            )
            SQL;
    }

    private function writeGeoJson(): void
    {
        $feature = function (string $id, string $kec, string $desa, float $offset): array {
            return [
                'type' => 'Feature',
                'properties' => [
                    'idsubsls' => $id,
                    'idsls' => substr($id, 0, 14),
                    'iddesa' => substr($id, 0, 10),
                    'idkec' => substr($id, 0, 7),
                    'kdprov' => '73',
                    'kdkab' => '16',
                    'kdkec' => '010',
                    'kddesa' => '018',
                    'kdsls' => substr($id, 10, 4),
                    'kdsubsls' => substr($id, 14, 2),
                    'nmkec' => $kec,
                    'nmdesa' => $desa,
                    'nmsls' => 'SLS Test',
                    'subsls' => 'Sub-SLS Test',
                    'periode' => '2025_1',
                    'luas' => 1.2,
                    'path' => 'private/source.geojson',
                    'email' => 'operator@example.test',
                ],
                'geometry' => [
                    'type' => 'MultiPolygon',
                    'coordinates' => [[[[119.8 + $offset, -3.5], [119.81 + $offset, -3.5], [119.81 + $offset, -3.49], [119.8 + $offset, -3.5]]]],
                ],
            ];
        };

        file_put_contents($this->geojsonPath, json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                $feature('7316010018000200', 'Maiwa', 'Limbuang', 0),
                $feature('7316010018000300', 'Maiwa', 'Limbuang', .02),
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function insertProgressRows(): void
    {
        $base = fn (string $snapshot, string $username, string $id, int $open, int $draft): array => [
            'snapshot_at' => $snapshot,
            'username' => $username,
            'nama_lengkap' => $username,
            'idsubsls' => $id,
            'kdprov' => '73',
            'kdkab' => '16',
            'kdkec' => '010',
            'kddes' => '018',
            'kdsls' => substr($id, 10, 4),
            'kdsubsls' => substr($id, 14, 2),
            'nmkec' => 'Maiwa',
            'nmdesa' => 'Limbuang',
            'nmsls' => 'SLS Test',
            'nmsubsls' => 'Sub-SLS Test',
            'region_total' => 100,
            'OPEN' => $open,
            'DRAFT' => $draft,
            'SUBMITTED BY Pencacah' => 10,
            'APPROVED BY Pengawas' => 10,
            'REJECTED BY Pengawas' => 0,
            'EDITED BY Pengawas' => 0,
            'REVOKED BY Pengawas' => 0,
            'SUBMITTED RESPONDENT' => 0,
            'COMPLETED BY Admin Kabupaten' => 2,
            'EDITED BY Admin Kabupaten' => 0,
            'REJECTED BY Admin Kabupaten' => 0,
            'REVOKED BY Admin Kabupaten' => 0,
        ];

        DB::connection('fasih')->table('progress_pengawas')->insert([
            $base('2026-07-07T08:00:00+08:00', 'user-1', '7316010018000200', 40, 10),
            $base('2026-07-07T08:00:00+08:00', 'user-2', '7316010018000300', 40, 10),
            $base('2026-07-08T08:00:00+08:00', 'user-1', '7316010018000200', 20, 10),
            $base('2026-07-08T08:00:00+08:00', 'user-2', '7316010018000300', 20, 10),
            array_replace(
                $base('2026-07-08T08:00:00+08:00', 'sentinel', '7316000000000000', 0, 0),
                ['region_total' => 0],
            ),
        ]);

        DB::connection('fasih')->table('progress_pencacah')->insert([
            array_replace($base('2026-07-08T08:00:00+08:00', 'ppl-1', '7316010018000200', 20, 10), [
                'user_id' => 'ppl-1',
                'nama_lengkap' => 'PENCACAH SATU',
            ]),
            array_replace($base('2026-07-08T08:00:00+08:00', 'ppl-1', '7316010018000300', 20, 10), [
                'user_id' => 'ppl-1',
                'nama_lengkap' => 'PENCACAH SATU',
            ]),
        ]);
    }

    private function insertOfficerRows(): void
    {
        DB::connection('fasih')->table('users')->insert([
            ['user_id' => 'ppl-1', 'email' => 'ppl@example.test', 'fullname' => 'PENCACAH SATU', 'role' => 'Pencacah', 'is_pencacah' => 1],
            ['user_id' => 'pml-1', 'email' => 'pml@example.test', 'fullname' => 'PENGAWAS SATU', 'role' => 'Pengawas', 'is_pencacah' => 0],
        ]);
        DB::connection('fasih')->table('petugas_wilayah')->insert([
            ['pencacah_user_id' => 'ppl-1', 'pengawas_user_id' => 'pml-1', 'idsubsls' => '7316010018000200'],
            ['pencacah_user_id' => 'ppl-1', 'pengawas_user_id' => 'pml-1', 'idsubsls' => '7316010018000300'],
        ]);
    }
}
