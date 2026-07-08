<?php

namespace App\Http\Controllers;

use App\Services\GeoSpatialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class GeoController extends Controller
{
    public function __construct(private readonly GeoSpatialService $geo) {}

    public function index(): Response
    {
        $dbPath = config('database.connections.fasih.database');
        $snapshots = file_exists($dbPath)
            ? DB::connection('fasih')->table('progress_pengawas')->distinct()->orderByDesc('snapshot_at')->pluck('snapshot_at')->all()
            : [];

        return Inertia::render('Peta', [
            'snapshots' => $snapshots,
            'geo_ready' => File::exists($this->geo->sourcePath()),
            'db_ready' => file_exists($dbPath),
        ]);
    }

    public function boundaries(Request $request): HttpResponse|JsonResponse
    {
        try {
            $prepared = $this->geo->prepare();
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        $etag = '"'.sha1_file($prepared['path']).'"';
        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        return response(File::get($prepared['path']), 200, [
            'Content-Type' => 'application/geo+json; charset=UTF-8',
            'Cache-Control' => 'private, max-age=86400',
            'ETag' => $etag,
        ]);
    }

    public function metrics(Request $request): JsonResponse
    {
        $data = $request->validate([
            'snapshot' => ['required', 'string'],
            'compare_snapshot' => ['nullable', 'string'],
            'role' => ['required', 'in:pengawas,pencacah'],
            'level' => ['required', 'in:'.implode(',', GeoSpatialService::LEVELS)],
            'metric' => ['required', 'in:'.implode(',', GeoSpatialService::METRICS)],
            'parent_id' => ['nullable', 'string', 'max:32'],
        ]);

        return response()->json($this->geo->metrics(
            $data['snapshot'],
            $data['role'],
            $data['level'],
            $data['metric'],
            $data['parent_id'] ?? null,
            $data['compare_snapshot'] ?? null,
        ));
    }

    public function region(Request $request, string $idsubsls): JsonResponse
    {
        $data = $request->validate([
            'snapshot' => ['required', 'string'],
            'role' => ['required', 'in:pengawas,pencacah'],
        ]);
        $region = $this->geo->region($idsubsls, $data['snapshot'], $data['role']);

        return $region ? response()->json($region) : response()->json(['message' => 'Data wilayah tidak ditemukan.'], 404);
    }

    public function officers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:pencacah,pengawas'],
            'scope_id' => ['nullable', 'string', 'max:32'],
        ]);

        return response()->json([
            'items' => $this->geo->officers($data['type'], $data['scope_id'] ?? null),
        ]);
    }

    public function officerRegions(Request $request, string $userId): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:pencacah,pengawas'],
        ]);
        $officer = $this->geo->officerRegions($userId, $data['type']);

        return $officer
            ? response()->json($officer)
            : response()->json(['message' => 'Petugas tidak ditemukan.'], 404);
    }

    public function regionDetail(Request $request, string $level, string $regionId): JsonResponse
    {
        $data = $request->validate([
            'snapshot' => ['required', 'string'],
        ]);

        if (! in_array($level, GeoSpatialService::LEVELS, true)) {
            return response()->json(['message' => 'Level wilayah tidak valid.'], 422);
        }

        $region = $this->geo->regionDetail($level, $regionId, $data['snapshot']);

        return $region
            ? response()->json($region)
            : response()->json(['message' => 'Data wilayah tidak ditemukan.'], 404);
    }
}
