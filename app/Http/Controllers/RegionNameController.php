<?php

namespace App\Http\Controllers;

use App\Models\RegionName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegionNameController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(RegionName::orderBy('code')->get());
    }

    public function importCsv(Request $request): JsonResponse
    {
        $request->validate(['csv' => ['required', 'string']]);

        $lines = preg_split('/\r?\n/', trim($request->input('csv'))) ?: [];
        $rows = [];
        $skipped = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = str_getcsv($line);
            if (count($parts) < 2) {
                $skipped++;

                continue;
            }

            $code = trim($parts[0]);
            $name = trim($parts[1]);
            if ($code === '' || $name === '') {
                $skipped++;

                continue;
            }

            $rows[] = ['code' => $code, 'name' => $name];
        }

        if (empty($rows)) {
            return response()->json(['message' => 'Tidak ada baris valid dalam CSV.'], 422);
        }

        RegionName::upsert($rows, ['code'], ['name']);

        return response()->json([
            'message' => count($rows).' nama wilayah berhasil diimport.'.($skipped ? " ($skipped baris dilewati)" : ''),
            'imported' => count($rows),
        ]);
    }

    public function destroy(string $code): JsonResponse
    {
        RegionName::where('code', $code)->delete();

        return response()->json(['message' => 'Data dihapus.']);
    }

    public function destroyAll(): JsonResponse
    {
        RegionName::truncate();

        return response()->json(['message' => 'Semua nama wilayah dihapus.']);
    }
}
