<?php

namespace Tests\Feature;

use App\Models\InitialPrelist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

class PrelistComparisonTest extends TestCase
{
    use RefreshDatabase;

    private string $fasihDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fasihDbPath = tempnam(sys_get_temp_dir(), 'fasih-prelist-test-');

        config()->set('database.connections.fasih.database', $this->fasihDbPath);
        config()->set('database.connections.fasih.read_only', false);

        DB::purge('fasih');
        DB::connection('fasih')->statement($this->progressTableSql('progress_pengawas'));
        DB::connection('fasih')->statement($this->progressTableSql('progress_pencacah'));
        DB::connection('fasih')->statement('
            CREATE TABLE assignments (
                assignment_id INTEGER PRIMARY KEY AUTOINCREMENT,
                kdkec TEXT,
                kddes TEXT,
                kdsls TEXT,
                kdsubsls TEXT,
                idsubsls TEXT
            )
        ');
        DB::connection('fasih')->statement('
            CREATE TABLE users (
                user_id TEXT PRIMARY KEY,
                email TEXT,
                fullname TEXT
            )
        ');
        DB::connection('fasih')->statement('
            CREATE TABLE petugas_wilayah (
                pencacah_user_id TEXT,
                pengawas_user_id TEXT,
                idsubsls TEXT
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

    public function test_import_initial_prelist_reads_expected_sheet_and_columns(): void
    {
        InitialPrelist::query()->delete();
        $xlsx = $this->makePrelistWorkbook();

        $this->artisan('prelist:import-awal', [
            'path' => $xlsx,
            '--sheet' => 'Rekap Prelist',
        ])->assertSuccessful();

        $this->assertDatabaseHas('initial_prelists', [
            'idsubsls' => '7316010001000101',
            'total_assignment_fasih' => 10,
            'source_sheet' => 'Rekap Prelist',
        ]);
        $this->assertSame(10, (int) InitialPrelist::query()->sum('total_assignment_fasih'));

        @unlink($xlsx);
    }

    public function test_initial_prelist_fixture_is_loaded_by_migration(): void
    {
        $this->assertSame(669, InitialPrelist::query()->count());
        $this->assertSame(78210, (int) InitialPrelist::query()->sum('total_assignment_fasih'));
        $this->assertDatabaseHas('initial_prelists', [
            'idsubsls' => '7316041007000100',
            'total_assignment_fasih' => 102,
        ]);
    }

    public function test_dashboard_api_uses_selected_prelist_basis_and_reports_coverage_gap(): void
    {
        InitialPrelist::query()->delete();
        $this->seedDashboardRows();
        $user = User::factory()->create(['email_verified_at' => now()]);

        $snapshot = urlencode('2026-07-20T00:00:00+00:00');

        $dynamic = $this->actingAs($user)->getJson("/api/data?snapshot={$snapshot}&role=pengawas&level=kec&prelist_basis=dynamic");
        $dynamic->assertOk()
            ->assertJsonPath('prelist_basis', 'dynamic')
            ->assertJsonPath('metrics.total_assignment', 160)
            ->assertJsonPath('prelist_comparison.dynamic_total', 160)
            ->assertJsonPath('prelist_comparison.initial_total', 130)
            ->assertJsonPath('prelist_comparison.matched_subsls', 2)
            ->assertJsonPath('prelist_comparison.initial_only_subsls', 1)
            ->assertJsonPath('prelist_comparison.initial_without_assignments_subsls', 2)
            ->assertJsonPath('prelist_comparison.initial_without_assignments_with_progress_subsls', 1)
            ->assertJsonPath('prelist_comparison.initial_without_assignments_missing_progress_subsls', 1)
            ->assertJsonPath('prelist_comparison.dynamic_only_subsls', 1)
            ->assertJsonPath('breakdown.0.prelist_dynamic', 100)
            ->assertJsonPath('breakdown.0.prelist_initial', 80)
            ->assertJsonPath('breakdown.0.prelist_delta', 20);

        $initial = $this->actingAs($user)->getJson("/api/data?snapshot={$snapshot}&role=pengawas&level=kec&prelist_basis=initial");
        $initial->assertOk()
            ->assertJsonPath('prelist_basis', 'initial')
            ->assertJsonPath('metrics.total_assignment', 130)
            ->assertJsonPath('breakdown.0.total', 80);

        $filtered = $this->actingAs($user)->getJson("/api/data?snapshot={$snapshot}&role=pengawas&level=kec&prelist_basis=initial&filter_kec[]=010");
        $filtered->assertOk()
            ->assertJsonPath('metrics.total_assignment', 80)
            ->assertJsonPath('prelist_comparison.dynamic_total', 100)
            ->assertJsonPath('prelist_comparison.initial_total', 80);
    }

    public function test_pencacah_breakdown_uses_selected_prelist_basis_as_denominator(): void
    {
        InitialPrelist::query()->delete();
        $idsubsls = '7316010001000101';
        $snapshot = urlencode('2026-07-20T00:00:00+00:00');
        $username = 'ppl@example.test';

        DB::connection('fasih')->table('users')->insert([
            'user_id' => 'ppl-1',
            'email' => $username,
            'fullname' => 'PPL Test',
        ]);
        DB::connection('fasih')->table('petugas_wilayah')->insert([
            'pencacah_user_id' => 'ppl-1',
            'pengawas_user_id' => null,
            'idsubsls' => $idsubsls,
        ]);
        DB::connection('fasih')->table('progress_pencacah')->insert([
            [
                ...$this->progressRow($idsubsls, '010', '001', 100, 20, 10),
                'username' => $username,
                'user_id' => 'ppl-1',
                'nama_lengkap' => 'PPL Test',
            ],
        ]);

        $assignmentRows = [];
        for ($i = 0; $i < 100; $i++) {
            $assignmentRows[] = [
                'idsubsls' => $idsubsls,
                'kdkec' => '7316010',
                'kddes' => '7316010001',
                'kdsls' => '73160100010001',
                'kdsubsls' => '01',
            ];
        }
        DB::connection('fasih')->table('assignments')->insert($assignmentRows);
        InitialPrelist::query()->insert([
            'idsubsls' => $idsubsls,
            'kdkec' => '7316010',
            'nmkec' => 'MAIWA',
            'kddes' => '001',
            'nmdesa' => 'PATONDON SALU',
            'kdsls' => '0001',
            'kdsubsls' => '01',
            'nmsls' => 'DUSUN JAMBU',
            'nmsubsls' => 'DUSUN JAMBU',
            'total_assignment_fasih' => 80,
            'source_sheet' => 'Rekap Prelist',
            'source_file' => 'Master.xlsx',
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $dynamic = $this->actingAs($user)->getJson("/api/data?snapshot={$snapshot}&role=pencacah&level=by_pencacah&prelist_basis=dynamic");
        $dynamic->assertOk()
            ->assertJsonPath('breakdown.0.key', $username)
            ->assertJsonPath('breakdown.0.total', 100)
            ->assertJsonPath('breakdown.0.statuses.OPEN', 20)
            ->assertJsonPath('breakdown.0.progress_pct', 70);

        $initial = $this->actingAs($user)->getJson("/api/data?snapshot={$snapshot}&role=pencacah&level=by_pencacah&prelist_basis=initial");
        $initial->assertOk()
            ->assertJsonPath('breakdown.0.key', $username)
            ->assertJsonPath('breakdown.0.total', 80)
            ->assertJsonPath('breakdown.0.statuses.OPEN', 20)
            ->assertJsonPath('breakdown.0.progress_pct', 87.5);
    }

    private function seedDashboardRows(): void
    {
        DB::connection('fasih')->table('progress_pengawas')->insert([
            $this->progressRow('7316010001000101', '010', '001', 100, 10, 10),
            $this->progressRow('7316020001000101', '020', '001', 50, 20, 5),
            $this->progressRow('7316041007000100', '041', '007', 10, 0, 0),
        ]);
        DB::connection('fasih')->table('progress_pencacah')->insert([
            $this->progressRow('7316010001000101', '010', '001', 100, 10, 10),
            $this->progressRow('7316020001000101', '020', '001', 50, 20, 5),
            $this->progressRow('7316041007000100', '041', '007', 10, 0, 0),
        ]);

        $assignmentRows = [];
        foreach ([
            ['7316010001000101', '7316010', '001', 100],
            ['7316020001000101', '7316020', '001', 50],
        ] as [$idsubsls, $kdkec, $kddes, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $assignmentRows[] = [
                    'idsubsls' => $idsubsls,
                    'kdkec' => $kdkec,
                    'kddes' => $kddes,
                    'kdsls' => substr($idsubsls, 10, 4),
                    'kdsubsls' => substr($idsubsls, 14, 2),
                ];
            }
        }
        DB::connection('fasih')->table('assignments')->insert($assignmentRows);

        InitialPrelist::query()->insert([
            [
                'idsubsls' => '7316010001000101',
                'kdkec' => '7316010',
                'nmkec' => 'MAIWA',
                'kddes' => '001',
                'nmdesa' => 'PATONDON SALU',
                'kdsls' => '0001',
                'kdsubsls' => '01',
                'nmsls' => 'DUSUN JAMBU',
                'nmsubsls' => 'DUSUN JAMBU',
                'total_assignment_fasih' => 80,
                'source_sheet' => 'Rekap Prelist',
                'source_file' => 'Master.xlsx',
                'imported_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'idsubsls' => '7316030001000101',
                'kdkec' => '7316030',
                'nmkec' => 'BUNGIN',
                'kddes' => '001',
                'nmdesa' => 'BUNGIN',
                'kdsls' => '0001',
                'kdsubsls' => '01',
                'nmsls' => 'DUSUN BUNGIN',
                'nmsubsls' => 'DUSUN BUNGIN',
                'total_assignment_fasih' => 40,
                'source_sheet' => 'Rekap Prelist',
                'source_file' => 'Master.xlsx',
                'imported_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'idsubsls' => '7316041007000100',
                'kdkec' => '7316041',
                'nmkec' => 'MALUA',
                'kddes' => '007',
                'nmdesa' => 'BONTO',
                'kdsls' => '0001',
                'kdsubsls' => '00',
                'nmsls' => 'DUSUN BUNTU LAMBA',
                'nmsubsls' => 'DUSUN BUNTU LAMBA',
                'total_assignment_fasih' => 10,
                'source_sheet' => 'Rekap Prelist',
                'source_file' => 'Master.xlsx',
                'imported_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function progressRow(string $idsubsls, string $kdkec, string $kddes, int $total, int $open, int $draft): array
    {
        $row = [
            'snapshot_at' => '2026-07-20T00:00:00+00:00',
            'username' => 'pml-'.$kdkec,
            'nama_lengkap' => 'Pengawas '.$kdkec,
            'kdprov' => '73',
            'nmprov' => 'SULAWESI SELATAN',
            'kdkab' => '7316',
            'nmkab' => 'ENREKANG',
            'kdkec' => $kdkec,
            'kddes' => $kddes,
            'kdsls' => substr($idsubsls, 10, 4),
            'kdsubsls' => substr($idsubsls, 14, 2),
            'idsubsls' => $idsubsls,
            'nmkec' => $kdkec === '7316010' ? 'MAIWA' : 'BARAKA',
            'nmdesa' => 'DESA UJI',
            'nmsls' => 'DUSUN UJI',
            'nmsubsls' => 'SUB UJI',
            'region_total' => $total,
            'OPEN' => $open,
            'DRAFT' => $draft,
            'SUBMITTED BY Pencacah' => $total - $open - $draft,
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

        return $row;
    }

    private function progressTableSql(string $table): string
    {
        return <<<SQL
            CREATE TABLE {$table} (
                snapshot_at TEXT NOT NULL,
                user_id TEXT NULL,
                username TEXT NOT NULL,
                nama_lengkap TEXT NULL,
                kdprov TEXT NULL,
                nmprov TEXT NULL,
                kdkab TEXT NULL,
                nmkab TEXT NULL,
                kdkec TEXT NOT NULL,
                kddes TEXT NOT NULL,
                kdsls TEXT NOT NULL,
                kdsubsls TEXT NOT NULL,
                idsubsls TEXT NOT NULL,
                nmkec TEXT NULL,
                nmdesa TEXT NULL,
                nmsls TEXT NULL,
                nmsubsls TEXT NULL,
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

    private function makePrelistWorkbook(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'initial-prelist-').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Rekap Prelist 090626" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="D1" t="inlineStr"><is><t>IDSUBSLS_25_2</t></is></c><c r="AD1" t="inlineStr"><is><t>TOTAL ASSIGNMENT FASIH</t></is></c></row><row r="2"/><row r="3"><c r="D3" t="inlineStr"><is><t>7316010001000101</t></is></c><c r="J3" t="inlineStr"><is><t>MAIWA</t></is></c><c r="L3" t="inlineStr"><is><t>PATONDON SALU</t></is></c><c r="O3" t="inlineStr"><is><t>DUSUN JAMBU</t></is></c><c r="AD3"><v>10</v></c></row></sheetData></worksheet>');
        $zip->close();

        return $path;
    }
}
