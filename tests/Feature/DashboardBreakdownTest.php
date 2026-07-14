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
                nmkec TEXT NULL,
                nmdesa TEXT NULL,
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
                'nmkec' => 'Kecamatan Satu',
                'nmdesa' => 'Desa Satu',
                'region_total' => 100,
                'OPEN' => 34,
                'DRAFT' => 10,
                'SUBMITTED BY Pencacah' => 12,
                'APPROVED BY Pengawas' => 8,
                'REJECTED BY Pengawas' => 4,
                'EDITED BY Pengawas' => 3,
                'REVOKED BY Pengawas' => 2,
                'SUBMITTED RESPONDENT' => 1,
                'COMPLETED BY Admin Kabupaten' => 5,
                'EDITED BY Admin Kabupaten' => 6,
                'REJECTED BY Admin Kabupaten' => 7,
                'REVOKED BY Admin Kabupaten' => 8,
            ],
        ]);

        $controller = new DashboardController;
        $method = new \ReflectionMethod($controller, 'calcBreakdown');
        $method->setAccessible(true);

        $query = DB::connection('fasih')->table('progress_pencacah');

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $method->invoke($controller, $query, 'by_pencacah', []);

        $this->assertSame(66, $rows[0]['lapangan_total']);
        $this->assertSame(66.0, $rows[0]['lapangan_pct']);
        $this->assertSame(5, $rows[0]['statuses']['COMPLETED BY Admin Kabupaten']);

        $totalsMethod = new \ReflectionMethod($controller, 'calcStatusTotals');
        $totalsMethod->setAccessible(true);

        /** @var array<string, int> $statusTotals */
        $statusTotals = $totalsMethod->invoke(
            $controller,
            DB::connection('fasih')->table('progress_pencacah'),
        );

        $this->assertSame(5, $statusTotals['COMPLETED BY Admin Kabupaten']);
        $this->assertSame(6, $statusTotals['EDITED BY Admin Kabupaten']);
        $this->assertSame(7, $statusTotals['REJECTED BY Admin Kabupaten']);
        $this->assertSame(8, $statusTotals['REVOKED BY Admin Kabupaten']);
    }

    public function test_composite_desa_filter_does_not_leak_to_same_desa_code_in_other_kecamatan(): void
    {
        DB::connection('fasih')->table('progress_pencacah')->insert([
            $this->progressRow('Baroko', '001', 'Desa Baroko', 10),
            $this->progressRow('Alla', '001', 'Sumillan', 20),
            $this->progressRow('Alla', '002', 'Desa Alla Lain', 30),
        ]);

        $controller = new DashboardController;
        $method = new \ReflectionMethod($controller, 'applyGeoFilter');
        $method->setAccessible(true);

        $query = DB::connection('fasih')->table('progress_pencacah');
        $method->invoke($controller, $query, ['01', '02'], ['02-001'], []);

        $this->assertSame(20, (int) $query->sum('region_total'));
    }

    /**
     * @return array<string, mixed>
     */
    private function progressRow(string $kec, string $desaCode, string $desa, int $total): array
    {
        return [
            'snapshot_at' => '2026-06-25T17:09:00+08:00',
            'username' => 'pencacah-'.$kec.'-'.$desaCode,
            'nama_lengkap' => null,
            'kdkec' => $kec === 'Baroko' ? '01' : '02',
            'kddes' => $desaCode,
            'nmkec' => $kec,
            'nmdesa' => $desa,
            'region_total' => $total,
            'OPEN' => 0,
            'DRAFT' => 0,
            'SUBMITTED BY Pencacah' => 0,
            'APPROVED BY Pengawas' => 0,
            'REJECTED BY Pengawas' => 0,
            'EDITED BY Pengawas' => 0,
            'REVOKED BY Pengawas' => 0,
            'SUBMITTED RESPONDENT' => 0,
            'COMPLETED BY Admin Kabupaten' => 0,
            'EDITED BY Admin Kabupaten' => 0,
            'REJECTED BY Admin Kabupaten' => 0,
            'REVOKED BY Admin Kabupaten' => 0,
        ];
    }
}
