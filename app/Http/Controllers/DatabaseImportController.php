<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatabaseImportController extends Controller
{
    private const SQLITE_MAGIC = "SQLite format 3\000";

    public function status(): JsonResponse
    {
        $path = config('database.connections.fasih.database');

        if (! file_exists($path)) {
            return response()->json(['exists' => false]);
        }

        $extra = [];
        try {
            $db = new \SQLite3($path, SQLITE3_OPEN_READONLY);
            $r = $db->querySingle('SELECT COUNT(*) FROM scrape_runs');
            $extra['scrape_runs_count'] = (int) $r;
            $r2 = $db->querySingle('SELECT COUNT(*) FROM assignments');
            $extra['assignments_count'] = (int) $r2;
            try {
                $r3 = $db->querySingle('SELECT COUNT(*) FROM assignments_v3');
                $extra['assignments_v3_count'] = (int) $r3;
            } catch (\Exception) {
                // table may not exist in older DB versions
            }
            $db->close();
        } catch (\Exception) {
            // older DB without new tables — ignore
        }

        $modifiedAt = filemtime($path);

        return response()->json(array_merge([
            'exists' => true,
            'size_mb' => round(filesize($path) / 1048576, 2),
            'modified_at' => $modifiedAt === false ? null : date('Y-m-d H:i:s', $modifiedAt),
            'path' => basename($path),
        ], $extra));
    }

    public function download(): BinaryFileResponse
    {
        $path = storage_path('app/fasih.db');

        if (! file_exists($path)) {
            abort(404, 'Database belum diimport.');
        }

        return response()->download($path, 'fasih.db', [
            'Content-Type' => 'application/octet-stream',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // PHP drops uploads silently when file exceeds upload_max_filesize.
        // UPLOAD_ERR_INI_SIZE = 1, UPLOAD_ERR_FORM_SIZE = 2.
        $uploadError = $_FILES['db']['error'] ?? null;
        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            $limit = ini_get('upload_max_filesize');

            return response()->json(['message' => "File terlalu besar. Limit server: {$limit}. Jalankan via `composer run dev`."], 422);
        }

        $request->validate([
            'db' => ['required', 'file'],
        ]);

        $file = $request->file('db');

        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            return response()->json(['message' => 'File upload tidak dapat dibaca.'], 422);
        }

        $magic = fread($handle, 16);
        fclose($handle);

        if ($magic !== self::SQLITE_MAGIC) {
            return response()->json(['message' => 'File bukan database SQLite yang valid.'], 422);
        }

        $dest = storage_path('app/fasih.db');
        $dir = dirname($dest);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        try {
            DB::connection('fasih')->disconnect();
        } catch (\Exception) {
            // no active connection, safe to continue
        }

        try {
            $file->move($dir, 'fasih.db');
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal menyimpan file: '.$e->getMessage()], 500);
        }

        $modifiedAt = filemtime($dest);

        return response()->json([
            'message' => 'Database berhasil diimport.',
            'size_mb' => round(filesize($dest) / 1048576, 2),
            'modified_at' => $modifiedAt === false ? null : date('Y-m-d H:i:s', $modifiedAt),
        ]);
    }
}
