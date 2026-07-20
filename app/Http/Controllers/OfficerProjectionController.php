<?php

namespace App\Http\Controllers;

use App\Services\OfficerProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OfficerProjectionController extends Controller
{
    public function __construct(private readonly OfficerProjectionService $service) {}

    public function index(): Response
    {
        $dbPath = config('database.connections.fasih.database');

        return Inertia::render('Proyeksi', [
            'db_ready' => is_string($dbPath) && file_exists($dbPath),
            'default_deadline' => '2026-08-31',
        ]);
    }

    public function officers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['nullable', 'in:pencacah,pengawas'],
            'deadline' => ['nullable', 'date_format:Y-m-d'],
            'snapshot' => ['nullable', 'string'],
            'kdkec' => ['nullable', 'array'],
            'kdkec.*' => ['string'],
            'kddes' => ['nullable', 'array'],
            'kddes.*' => ['string'],
            'kdsls' => ['nullable', 'array'],
            'kdsls.*' => ['string'],
            'idsubsls' => ['nullable', 'array'],
            'idsubsls.*' => ['string'],
            'status' => ['nullable', 'in:done,on_track,behind,no_rate'],
            'search' => ['nullable', 'string'],
            'sort' => ['nullable', 'string'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        return response()->json($this->service->list($validated));
    }

    public function detail(string $officerKey, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['nullable', 'in:pencacah,pengawas'],
            'deadline' => ['nullable', 'date_format:Y-m-d'],
            'snapshot' => ['nullable', 'string'],
            'kdkec' => ['nullable', 'array'],
            'kdkec.*' => ['string'],
            'kddes' => ['nullable', 'array'],
            'kddes.*' => ['string'],
            'kdsls' => ['nullable', 'array'],
            'kdsls.*' => ['string'],
            'idsubsls' => ['nullable', 'array'],
            'idsubsls.*' => ['string'],
        ]);

        return response()->json($this->service->detail($officerKey, $validated));
    }
}
