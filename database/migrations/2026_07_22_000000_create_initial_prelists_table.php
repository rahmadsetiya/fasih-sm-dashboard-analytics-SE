<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('initial_prelists', function (Blueprint $table) {
            $table->id();
            $table->string('idsubsls')->unique();
            $table->string('kdkec')->nullable()->index();
            $table->string('nmkec')->nullable();
            $table->string('kddes')->nullable()->index();
            $table->string('nmdesa')->nullable();
            $table->string('kdsls')->nullable()->index();
            $table->string('kdsubsls')->nullable();
            $table->string('nmsls')->nullable();
            $table->string('nmsubsls')->nullable();
            $table->unsignedInteger('total_assignment_fasih')->default(0);
            $table->string('source_sheet')->nullable();
            $table->string('source_file')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });

        $this->seedInitialPrelists();
    }

    public function down(): void
    {
        Schema::dropIfExists('initial_prelists');
    }

    private function seedInitialPrelists(): void
    {
        $fixturePath = database_path('data/initial_prelists.json');
        if (! is_file($fixturePath)) {
            return;
        }

        $rows = json_decode((string) file_get_contents($fixturePath), true);
        if (! is_array($rows) || $rows === []) {
            return;
        }

        $now = now();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('initial_prelists')->upsert(
                array_map(fn (array $row) => [
                    'idsubsls' => (string) ($row['idsubsls'] ?? ''),
                    'kdkec' => $row['kdkec'] ?? null,
                    'nmkec' => $row['nmkec'] ?? null,
                    'kddes' => $row['kddes'] ?? null,
                    'nmdesa' => $row['nmdesa'] ?? null,
                    'kdsls' => $row['kdsls'] ?? null,
                    'kdsubsls' => $row['kdsubsls'] ?? null,
                    'nmsls' => $row['nmsls'] ?? null,
                    'nmsubsls' => $row['nmsubsls'] ?? null,
                    'total_assignment_fasih' => (int) ($row['total_assignment_fasih'] ?? 0),
                    'source_sheet' => 'Rekap Prelist 090626',
                    'source_file' => 'database/data/initial_prelists.json',
                    'imported_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk),
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
    }
};
