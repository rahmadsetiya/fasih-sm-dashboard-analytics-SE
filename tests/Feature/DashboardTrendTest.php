<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTrendTest extends TestCase
{
    private string $fasihDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fasihDbPath = tempnam(sys_get_temp_dir(), 'fasih-trend-test-');

        config()->set('database.connections.fasih.database', $this->fasihDbPath);
        config()->set('database.connections.fasih.read_only', false);

        DB::purge('fasih');

        DB::connection('fasih')->statement('
            CREATE TABLE progress_pengawas (
                snapshot_at TEXT NOT NULL,
                region_total INTEGER NOT NULL,
                "OPEN" INTEGER NOT NULL,
                "DRAFT" INTEGER NOT NULL,
                "SUBMITTED BY Pencacah" INTEGER NOT NULL,
                "APPROVED BY Pengawas" INTEGER NOT NULL
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

    public function test_trend_uses_only_the_latest_snapshot_from_each_date(): void
    {
        DB::connection('fasih')->table('progress_pengawas')->insert([
            [
                'snapshot_at' => '2026-06-26T09:00:00+08:00',
                'region_total' => 100,
                'OPEN' => 80,
                'DRAFT' => 10,
                'SUBMITTED BY Pencacah' => 5,
                'APPROVED BY Pengawas' => 2,
            ],
            [
                'snapshot_at' => '2026-06-26T18:00:00+08:00',
                'region_total' => 100,
                'OPEN' => 60,
                'DRAFT' => 10,
                'SUBMITTED BY Pencacah' => 20,
                'APPROVED BY Pengawas' => 15,
            ],
            [
                'snapshot_at' => '2026-06-27T12:00:00+08:00',
                'region_total' => 100,
                'OPEN' => 50,
                'DRAFT' => 10,
                'SUBMITTED BY Pencacah' => 25,
                'APPROVED BY Pengawas' => 20,
            ],
        ]);

        $controller = new DashboardController;
        $method = new \ReflectionMethod($controller, 'calcTrend');
        $method->setAccessible(true);

        /** @var array<int, array<string, int|float|string>> $trend */
        $trend = $method->invoke($controller, 'progress_pengawas', [], [], []);

        $this->assertSame(
            [
                [
                    'snapshot_at' => '2026-06-26T18:00:00+08:00',
                    'progress_pct' => 30.0,
                    'submitted_pct' => 20.0,
                    'approved_pct' => 15.0,
                    'total' => 100,
                ],
                [
                    'snapshot_at' => '2026-06-27T12:00:00+08:00',
                    'progress_pct' => 40.0,
                    'submitted_pct' => 25.0,
                    'approved_pct' => 20.0,
                    'total' => 100,
                ],
            ],
            $trend,
        );
    }

    public function test_trend_keeps_the_latest_seven_dates(): void
    {
        DB::connection('fasih')->table('progress_pengawas')->insert([
            [
                'snapshot_at' => '2026-06-19T08:00:00+08:00',
                'region_total' => 100,
                'OPEN' => 95,
                'DRAFT' => 3,
                'SUBMITTED BY Pencacah' => 1,
                'APPROVED BY Pengawas' => 0,
            ],
            [
                'snapshot_at' => '2026-06-20T08:00:00+08:00',
                'region_total' => 100,
                'OPEN' => 90,
                'DRAFT' => 5,
                'SUBMITTED BY Pencacah' => 3,
                'APPROVED BY Pengawas' => 1,
            ],
            [
                'snapshot_at' => '2026-06-21T08:00:00+08:00',
                'region_total' => 100,
                'OPEN' => 75,
                'DRAFT' => 10,
                'SUBMITTED BY Pencacah' => 8,
                'APPROVED BY Pengawas' => 4,
            ],
            [
                'snapshot_at' => '2026-06-22T18:00:00+08:00',
                'region_total' => 100,
                'OPEN' => 70,
                'DRAFT' => 10,
                'SUBMITTED BY Pencacah' => 10,
                'APPROVED BY Pengawas' => 5,
            ],
            [
                'snapshot_at' => '2026-06-23T18:00:00+08:00',
                'region_total' => 100,
                'OPEN' => 65,
                'DRAFT' => 10,
                'SUBMITTED BY Pencacah' => 12,
                'APPROVED BY Pengawas' => 6,
            ],
            [
                'snapshot_at' => '2026-06-24T18:00:00+08:00',
                'region_total' => 100,
                'OPEN' => 60,
                'DRAFT' => 10,
                'SUBMITTED BY Pencacah' => 15,
                'APPROVED BY Pengawas' => 8,
            ],
            [
                'snapshot_at' => '2026-06-25T09:00:00+08:00',
                'region_total' => 100,
                'OPEN' => 58,
                'DRAFT' => 10,
                'SUBMITTED BY Pencacah' => 16,
                'APPROVED BY Pengawas' => 9,
            ],
            [
                'snapshot_at' => '2026-06-25T17:09:00+08:00',
                'region_total' => 100,
                'OPEN' => 55,
                'DRAFT' => 10,
                'SUBMITTED BY Pencacah' => 18,
                'APPROVED BY Pengawas' => 10,
            ],
        ]);

        $controller = new DashboardController;
        $method = new \ReflectionMethod($controller, 'calcTrend');
        $method->setAccessible(true);

        /** @var array<int, array<string, int|float|string>> $trend */
        $trend = $method->invoke($controller, 'progress_pengawas', [], [], []);

        $this->assertSame(
            [
                '2026-06-19T08:00:00+08:00',
                '2026-06-20T08:00:00+08:00',
                '2026-06-21T08:00:00+08:00',
                '2026-06-22T18:00:00+08:00',
                '2026-06-23T18:00:00+08:00',
                '2026-06-24T18:00:00+08:00',
                '2026-06-25T17:09:00+08:00',
            ],
            array_column($trend, 'snapshot_at'),
        );
    }
}
