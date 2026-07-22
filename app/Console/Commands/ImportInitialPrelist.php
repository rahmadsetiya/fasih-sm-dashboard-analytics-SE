<?php

namespace App\Console\Commands;

use App\Services\InitialPrelistImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportInitialPrelist extends Command
{
    protected $signature = 'prelist:import-awal
        {path : Path file Master SE2026 XLSX}
        {--sheet=Rekap Prelist : Nama atau potongan nama sheet rekap prelist}';

    protected $description = 'Import prelist awal dari workbook Master SE2026 ke app database.';

    public function handle(InitialPrelistImporter $importer): int
    {
        try {
            $result = $importer->import(
                (string) $this->argument('path'),
                (string) $this->option('sheet'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Import prelist awal selesai.');
        $this->table(
            ['Metric', 'Value'],
            collect($result)
                ->map(fn (int|string $value, string $key) => [$key, (string) $value])
                ->values()
                ->all(),
        );

        return self::SUCCESS;
    }
}
