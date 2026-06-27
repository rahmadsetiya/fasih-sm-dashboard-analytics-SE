<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $latestSnapshot = null;
        $dbPath = config('database.connections.fasih.database');
        if (file_exists($dbPath)) {
            try {
                $row = DB::connection('fasih')
                    ->table('progress_pengawas')
                    ->selectRaw('MAX(snapshot_at) as latest')
                    ->first();
                $latestSnapshot = $row->latest ?? null;
            } catch (\Exception) {
                // fasih.db exists but table missing — ignore
            }
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user() ? array_merge(
                    $request->user()->toArray(),
                    ['is_admin' => (bool) $request->user()->is_admin],
                ) : null,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'latest_snapshot' => $latestSnapshot,
        ];
    }
}
