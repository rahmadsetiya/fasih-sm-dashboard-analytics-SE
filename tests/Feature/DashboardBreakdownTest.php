<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardBreakdownTest extends TestCase
{
    private string $fasihDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fasihDbPath = tempnam(sys_get_temp_dir(), 'fasih-breakdown-test-');

        config()->set('database.connections.fasih.database', $this->fasihDbPath);
        config()->set('database.connections.fasih.read_only', false);

        DB::purge('fasih');

        DB::connection('fasih')->statement('
            CREATE TABLE progress_pencacah (
                snapshot_at TEXT NOT NULL,
                username TEXT NOT NULL,
                nama_lengkap TEXT NULL,
                kdkec TEXT NOT NULL,
                kddes TEXT NOT NULL,
                region_total INTEGER NOT NULL,
                "OPEN" INTEGER NOT NULL,
                "DRAFT" INTEGER NOT NULL,
                "SUBMITTED BY Pencacah" INTEGER NOT NULL,
                "APPROVED BY Pengawas" INTEGER NOT NULL,
                "REJECTED BY Pengawas" INTEGER NOT NULL,
                "EDITED BY Pengawas" INTEGER NOT NULL,
                "REVOKED BY Pengawas" INTEGER NOT NULL,
                "SUBMITTED RESPONDENT" INTEGER NOT NULL
            )
        ');
    }

    protected function tearDown(): void
    {
        DB::disconnect('fasih');

        if (isset($this->fasihDbPath) && file_exists($this->fasihDbPath)) {
            @unlink($this->fasihDbPath);
        }

        parent::tearDown();
    }

    public function test_breakdown_by_pencacah_includes_progres_lapangan_total_and_percentage(): void
    {
        DB::connection('fasih')->table('progress_pencacah')->insert([
            [
                'snapshot_at' => '2026-06-25T17:09:00+08:00',
                'username' => 'pencacah-1',
                'nama_lengkap' => 'Pencacah Satu',
                'kdkec' => '01',
                'kddes' => '001',
                'region_total' => 100,
                'OPEN' => 55,
                'DRAFT' => 10,
                'SUBMITTED BY Pencacah' => 12,
                'APPROVED BY Pengawas' => 8,
                'REJECTED BY Pengawas' => 4,
                'EDITED BY Pengawas' => 3,
                'REVOKED BY Pengawas' => 2,
                'SUBMITTED RESPONDENT' => 1,
            ],
        ]);

        $controller = new DashboardController;
        $method = new \ReflectionMethod($controller, 'calcBreakdown');
        $method->setAccessible(true);

        $query = DB::connection('fasih')->table('progress_pencacah');

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $method->invoke($controller, $query, 'by_pencacah', []);

        $this->assertSame(39, $rows[0]['lapangan_total']);
        $this->assertSame(39.0, $rows[0]['lapangan_pct']);
    }
}
