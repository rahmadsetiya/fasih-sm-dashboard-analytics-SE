<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OfficerProjectionTest extends TestCase
{
    use RefreshDatabase;

    private string $fasihDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fasihDbPath = tempnam(sys_get_temp_dir(), 'fasih-projection-test-');

        config()->set('database.connections.fasih.database', $this->fasihDbPath);
        config()->set('database.connections.fasih.read_only', false);

        DB::purge('fasih');
        DB::connection('fasih')->statement($this->tableSql('progress_pencacah'));
        DB::connection('fasih')->statement($this->tableSql('progress_pengawas'));
    }

    protected function tearDown(): void
    {
        DB::disconnect('fasih');

        if (isset($this->fasihDbPath) && file_exists($this->fasihDbPath)) {
            @unlink($this->fasihDbPath);
        }

        parent::tearDown();
    }

    public function test_projection_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/projections/officers')->assertUnauthorized();
    }

    public function test_projection_calculates_daily_target_and_statuses(): void
    {
        $this->seedProjectionRows();
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->getJson('/api/projections/officers?role=pencacah&deadline=2026-08-31&sort=name&direction=asc');

        $response->assertOk()
            ->assertJsonPath('summary.snapshot', '2026-07-20T00:00:00+00:00')
            ->assertJsonPath('summary.days_left', 43)
            ->assertJsonPath('summary.remaining_total', 130)
            ->assertJsonPath('summary.required_daily_submit', 4)
            ->assertJsonPath('summary.counts_by_status.on_track', 1)
            ->assertJsonPath('summary.counts_by_status.behind', 1)
            ->assertJsonPath('rows.0.name', 'Ppl Aman')
            ->assertJsonPath('rows.0.projection_status', 'on_track')
            ->assertJsonPath('rows.0.required_daily_submit', 1)
            ->assertJsonPath('rows.0.rejected_total', 0)
            ->assertJsonPath('rows.0.rejection_rate', 0)
            ->assertJsonPath('rows.0.reject_risk', 'low')
            ->assertJsonMissing(['email' => 'aman@example.test']);
    }

    public function test_projection_detail_returns_status_breakdown_history_and_regions(): void
    {
        $this->seedProjectionRows();
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->getJson('/api/projections/officers/ppl-aman?role=pencacah&deadline=2026-08-31');

        $response->assertOk()
            ->assertJsonPath('officer.name', 'Ppl Aman')
            ->assertJsonPath('metrics.submitted_total', 80)
            ->assertJsonPath('metrics.quality_adjusted_daily_rate', 4)
            ->assertJsonPath('status_totals.COMPLETED BY Admin Kabupaten', 5)
            ->assertJsonPath('daily_history.0.date', '2026-07-10')
            ->assertJsonPath('target_vs_actual.0.actual_submit', 40)
            ->assertJsonPath('regions.0.idsubsls', '7316010001000101')
            ->assertJsonMissingPath('officer.email');
    }

    public function test_projection_composite_desa_filter_does_not_leak_same_local_code(): void
    {
        DB::connection('fasih')->table('progress_pencacah')->insert([
            $this->progressRow('2026-07-20T00:00:00+00:00', 'ppl-baroko', 'ppl baroko', 10, 0, 0, [
                'SUBMITTED BY Pencacah' => 10,
            ], '7316010001000101', '01', '001'),
            $this->progressRow('2026-07-20T00:00:00+00:00', 'ppl-sumillan', 'ppl sumillan', 20, 0, 0, [
                'SUBMITTED BY Pencacah' => 20,
            ], '7316020001000101', '02', '001'),
            $this->progressRow('2026-07-20T00:00:00+00:00', 'ppl-alla-lain', 'ppl alla lain', 30, 0, 0, [
                'SUBMITTED BY Pencacah' => 30,
            ], '7316020002000101', '02', '002'),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->getJson('/api/projections/officers?role=pencacah&deadline=2026-08-31&kdkec[]=01&kdkec[]=02&kddes[]=02-001')
            ->assertOk()
            ->assertJsonPath('summary.total_assignment', 20)
            ->assertJsonPath('rows.0.name', 'Ppl Sumillan');
    }

    public function test_projection_reject_rate_adjusts_effective_performance(): void
    {
        DB::connection('fasih')->table('progress_pencacah')->insert([
            $this->progressRow('2026-07-10T00:00:00+00:00', 'ppl-reject', 'ppl reject tinggi', 400, 400, 0, []),
            $this->progressRow('2026-07-20T00:00:00+00:00', 'ppl-reject', 'ppl reject tinggi', 400, 300, 0, [
                'SUBMITTED BY Pencacah' => 50,
                'REJECTED BY Pengawas' => 40,
                'REJECTED BY Admin Kabupaten' => 10,
            ]),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->getJson('/api/projections/officers?role=pencacah&deadline=2026-08-31')
            ->assertOk()
            ->assertJsonPath('rows.0.submitted_total', 100)
            ->assertJsonPath('rows.0.rejected_total', 50)
            ->assertJsonPath('rows.0.rejection_rate', 50)
            ->assertJsonPath('rows.0.actual_daily_rate', 10)
            ->assertJsonPath('rows.0.quality_adjusted_daily_rate', 5)
            ->assertJsonPath('rows.0.required_daily_submit', 7)
            ->assertJsonPath('rows.0.reject_risk', 'high')
            ->assertJsonPath('rows.0.projection_status', 'behind');
    }

    public function test_projection_returns_empty_state_when_fasih_db_is_missing(): void
    {
        DB::disconnect('fasih');
        @unlink($this->fasihDbPath);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->getJson('/api/projections/officers?role=pencacah')
            ->assertOk()
            ->assertJsonPath('empty', true)
            ->assertJsonPath('rows', []);
    }

    private function seedProjectionRows(): void
    {
        DB::connection('fasih')->table('progress_pencacah')->insert([
            $this->progressRow('2026-07-10T00:00:00+00:00', 'ppl-aman', 'ppl aman', 100, 50, 10, [
                'SUBMITTED BY Pencacah' => 20,
                'SUBMITTED RESPONDENT' => 10,
                'COMPLETED BY Admin Kabupaten' => 10,
            ]),
            $this->progressRow('2026-07-20T00:00:00+00:00', 'ppl-aman', 'ppl aman', 100, 10, 10, [
                'SUBMITTED BY Pencacah' => 40,
                'COMPLETED BY Admin Kabupaten' => 5,
                'APPROVED BY Pengawas' => 10,
                'EDITED BY Pengawas' => 5,
                'REVOKED BY Pengawas' => 5,
                'SUBMITTED RESPONDENT' => 5,
                'EDITED BY Admin Kabupaten' => 5,
                'REVOKED BY Admin Kabupaten' => 5,
            ]),
            $this->progressRow('2026-07-10T00:00:00+00:00', 'ppl-risk', 'ppl risiko', 120, 100, 10, [
                'SUBMITTED BY Pencacah' => 0,
            ], '7316010001000201'),
            $this->progressRow('2026-07-20T00:00:00+00:00', 'ppl-risk', 'ppl risiko', 120, 100, 10, [
                'SUBMITTED BY Pencacah' => 10,
            ], '7316010001000201'),
        ]);
    }

    /**
     * @param  array<string, int>  $statuses
     * @return array<string, mixed>
     */
    private function progressRow(
        string $snapshot,
        string $userId,
        string $name,
        int $total,
        int $open,
        int $draft,
        array $statuses,
        string $idsubsls = '7316010001000101',
        string $kdkec = '7316010',
        string $kddes = '001',
    ): array {
        $row = [
            'snapshot_at' => $snapshot,
            'user_id' => $userId,
            'username' => $userId,
            'email' => $userId.'@example.test',
            'nama_lengkap' => $name,
            'user_total' => $total,
            'idsubsls' => $idsubsls,
            'kdkec' => $kdkec,
            'kddes' => $kddes,
            'kdsls' => substr($idsubsls, 10, 4),
            'kdsubsls' => substr($idsubsls, 14, 2),
            'nmkec' => 'MAIWA',
            'nmdesa' => 'PATONDON SALU',
            'nmsls' => 'DUSUN JAMBU',
            'nmsubsls' => 'DUSUN JAMBU',
            'region_total' => $total,
            'OPEN' => $open,
            'DRAFT' => $draft,
        ];

        foreach ($this->statusCols() as $status) {
            $row[$status] = $statuses[$status] ?? 0;
        }

        return $row;
    }

    private function tableSql(string $table): string
    {
        return <<<SQL
            CREATE TABLE {$table} (
                snapshot_at TEXT NOT NULL,
                user_id TEXT,
                username TEXT,
                email TEXT,
                nama_lengkap TEXT,
                user_total INTEGER NOT NULL,
                idsubsls TEXT,
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

    /**
     * @return list<string>
     */
    private function statusCols(): array
    {
        return [
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
    }
}
