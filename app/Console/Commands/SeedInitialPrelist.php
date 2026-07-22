<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class SeedInitialPrelist extends Command
{
    protected $signature = 'prelist:seed-awal';

    protected $description = 'Seed prelist awal bawaan dari fixture JSON ke app database.';

    public function handle(): int
    {
        if (! Schema::hasTable('initial_prelists')) {
            $this->error('Tabel initial_prelists belum ada. Jalankan php artisan migrate --force terlebih dahulu.');

            return self::FAILURE;
        }

        try {
            $result = $this->seedFromFixture();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Seed prelist awal selesai.');
        $this->table(
            ['Metric', 'Value'],
            collect($result)
                ->map(fn (int|string $value, string $key) => [$key, (string) $value])
                ->values()
                ->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string, int|string>
     */
    private function seedFromFixture(): array
    {
        $fixturePath = database_path('data/initial_prelists.json');
        if (! is_file($fixturePath)) {
            throw new RuntimeException("Fixture prelist awal tidak ditemukan: {$fixturePath}");
        }

        $rows = json_decode((string) file_get_contents($fixturePath), true);
        if (! is_array($rows) || $rows === []) {
            throw new RuntimeException("Fixture prelist awal kosong atau tidak valid: {$fixturePath}");
        }

        $existingBefore = (int) DB::table('initial_prelists')->count();
        $now = now();
        $records = [];
        $totalAssignment = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $idsubsls = trim((string) ($row['idsubsls'] ?? ''));
            if (! preg_match('/^\d{16}$/', $idsubsls)) {
                continue;
            }

            $assignmentTotal = max(0, (int) ($row['total_assignment_fasih'] ?? 0));
            $totalAssignment += $assignmentTotal;
            $records[$idsubsls] = [
                'idsubsls' => $idsubsls,
                'kdkec' => $row['kdkec'] ?? null,
                'nmkec' => $row['nmkec'] ?? null,
                'kddes' => $row['kddes'] ?? null,
                'nmdesa' => $row['nmdesa'] ?? null,
                'kdsls' => $row['kdsls'] ?? null,
                'kdsubsls' => $row['kdsubsls'] ?? null,
                'nmsls' => $row['nmsls'] ?? null,
                'nmsubsls' => $row['nmsubsls'] ?? null,
                'total_assignment_fasih' => $assignmentTotal,
                'source_sheet' => 'Rekap Prelist 090626',
                'source_file' => 'database/data/initial_prelists.json',
                'imported_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($records): void {
            foreach (array_chunk(array_values($records), 500) as $chunk) {
                DB::table('initial_prelists')->upsert(
                    $chunk,
                    ['idsubsls'],
                    [
                        'kdkec',
                        'nmkec',
                        'kddes',
                        'nmdesa',
                        'kdsls',
                        'kdsubsls',
                        'nmsls',
                        'nmsubsls',
                        'total_assignment_fasih',
                        'source_sheet',
                        'source_file',
                        'imported_at',
                        'updated_at',
                    ],
                );
            }
        });

        return [
            'source_file' => 'database/data/initial_prelists.json',
            'rows_seeded' => count($records),
            'total_assignment' => $totalAssignment,
            'existing_before' => $existingBefore,
            'rows_after' => (int) DB::table('initial_prelists')->count(),
        ];
    }
}
