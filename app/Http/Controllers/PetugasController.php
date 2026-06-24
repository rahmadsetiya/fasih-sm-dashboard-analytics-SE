<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PetugasController extends Controller
{
    private function geoParams(Request $r): array
    {
        return [
            'kdkec' => preg_replace('/[^0-9]/', '', $r->input('kdkec', '')),
            'kddes' => preg_replace('/[^0-9]/', '', $r->input('kddes', '')),
            'kdsls' => preg_replace('/[^0-9]/', '', $r->input('kdsls', '')),
            'kdsubsls' => preg_replace('/[^0-9]/', '', $r->input('kdsubsls', '')),
        ];
    }

    private function applyGeoFilter($query, array $geo, string $prefix = 'a'): void
    {
        if ($geo['kdkec']) {
            $query->where("$prefix.kdkec", $geo['kdkec']);
        }
        if ($geo['kddes']) {
            $query->where("$prefix.kddes", $geo['kddes']);
        }
        if ($geo['kdsls']) {
            $query->where("$prefix.kdsls", $geo['kdsls']);
        }
        if ($geo['kdsubsls']) {
            $query->where("$prefix.kdsubsls", $geo['kdsubsls']);
        }
    }

    public function index(): Response
    {
        $dbPath = config('database.connections.fasih.database');

        $wilayah = [];
        if (file_exists($dbPath)) {
            $wilayah = DB::connection('fasih')
                ->table('wilayah')
                ->whereIn('level', [3, 4, 5, 6])
                ->select('uuid', 'level', 'full_code as code', 'name', 'parent_uuid')
                ->orderBy('level')
                ->orderBy('name')
                ->get()
                ->groupBy('level')
                ->map(fn ($rows) => $rows->values()->all())
                ->toArray();
        }

        return Inertia::render('Petugas', [
            'db_ready' => file_exists($dbPath),
            'wilayah' => $wilayah,
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $dbPath = config('database.connections.fasih.database');
        if (! file_exists($dbPath)) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $geo = $this->geoParams($request);

        $query = DB::connection('fasih')
            ->table('assignments as a')
            ->join('users as u', 'u.user_id', '=', 'a.pencacah_user_id')
            ->selectRaw("
                hex(u.user_id) as uid,
                COALESCE(NULLIF(u.fullname,''), u.email) as nama,
                u.email,
                COUNT(*) as total,
                SUM(CASE WHEN a.assignment_status_id = 0 THEN 1 ELSE 0 END) as draft,
                SUM(CASE WHEN a.assignment_status_id = 1 THEN 1 ELSE 0 END) as submitted,
                SUM(CASE WHEN a.assignment_status_id = 2 THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN a.assignment_status_id = 3 THEN 1 ELSE 0 END) as rejected
            ");

        $this->applyGeoFilter($query, $geo);

        $rows = $query->groupByRaw('u.user_id')->get()
            ->map(function ($r) {
                $total = (int) ($r->total ?: 1);
                $submitted = (int) ($r->submitted ?? 0);
                $approved = (int) ($r->approved ?? 0);
                $rejected = (int) ($r->rejected ?? 0);
                $reviewed = $submitted + $approved + $rejected;

                return [
                    'uid' => $r->uid,
                    'nama' => $r->nama,
                    'email' => $r->email,
                    'total' => $total,
                    'draft' => (int) ($r->draft ?? 0),
                    'submitted' => $submitted,
                    'approved' => $approved,
                    'rejected' => $rejected,
                    'rejection_rate' => $reviewed > 0 ? round($rejected / $reviewed * 100, 1) : 0.0,
                    'progress_pct' => round(($total - (int) ($r->draft ?? 0)) / $total * 100, 1),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();

        return response()->json(['data' => $rows, 'total' => count($rows)]);
    }

    public function turnaround(Request $request): JsonResponse
    {
        $dbPath = config('database.connections.fasih.database');
        if (! file_exists($dbPath)) {
            return response()->json(['pencacah' => [], 'pengawas' => []]);
        }

        $geo = $this->geoParams($request);

        $conditions = [];
        $params = [];
        foreach (['kdkec' => 'c.kdkec', 'kddes' => 'c.kddes', 'kdsls' => 'c.kdsls', 'kdsubsls' => 'c.kdsubsls'] as $key => $col) {
            if ($geo[$key]) {
                $conditions[] = "$col = ?";
                $params[] = $geo[$key];
            }
        }
        $extraWhere = $conditions ? 'AND '.implode(' AND ', $conditions) : '';

        $pencacahRows = DB::connection('fasih')->select("
            SELECT
                hex(c.pencacah_user_id) as uid,
                COALESCE(NULLIF(u.fullname,''), u.email) as nama,
                ROUND(AVG((julianday(c.change_date) - julianday(d.last_draft)) * 24 * 60), 1) as avg_minutes,
                COUNT(*) as sample_count
            FROM assignment_status_changes c
            JOIN (
                SELECT assignment_id, MAX(change_date) as last_draft
                FROM assignment_status_changes
                WHERE to_status_id = 0
                GROUP BY assignment_id
            ) d ON d.assignment_id = c.assignment_id
              AND c.change_date > d.last_draft
            LEFT JOIN users u ON u.user_id = c.pencacah_user_id
            WHERE c.to_status_id = 1
              AND c.pencacah_user_id IS NOT NULL
              $extraWhere
            GROUP BY c.pencacah_user_id
            HAVING sample_count >= 3
            ORDER BY avg_minutes ASC
        ", $params);

        $pengawasRows = DB::connection('fasih')->select("
            SELECT
                hex(c.pengawas_user_id) as uid,
                COALESCE(NULLIF(u.fullname,''), u.email) as nama,
                ROUND(AVG((julianday(c.change_date) - julianday(s.last_submit)) * 24 * 60), 1) as avg_minutes,
                COUNT(*) as sample_count,
                SUM(CASE WHEN c.to_status_id = 2 THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN c.to_status_id = 3 THEN 1 ELSE 0 END) as rejected_count
            FROM assignment_status_changes c
            JOIN (
                SELECT assignment_id, MAX(change_date) as last_submit
                FROM assignment_status_changes
                WHERE to_status_id = 1
                GROUP BY assignment_id
            ) s ON s.assignment_id = c.assignment_id
              AND c.change_date > s.last_submit
            LEFT JOIN users u ON u.user_id = c.pengawas_user_id
            WHERE c.to_status_id IN (2, 3)
              AND c.pengawas_user_id IS NOT NULL
              $extraWhere
            GROUP BY c.pengawas_user_id
            HAVING sample_count >= 1
            ORDER BY avg_minutes ASC
        ", $params);

        return response()->json([
            'pencacah' => collect($pencacahRows)->map(fn ($r) => [
                'uid' => $r->uid,
                'nama' => $r->nama,
                'avg_minutes' => (float) ($r->avg_minutes ?? 0),
                'sample_count' => (int) $r->sample_count,
            ])->values()->all(),
            'pengawas' => collect($pengawasRows)->map(fn ($r) => [
                'uid' => $r->uid,
                'nama' => $r->nama,
                'avg_minutes' => (float) ($r->avg_minutes ?? 0),
                'sample_count' => (int) $r->sample_count,
                'approved_count' => (int) $r->approved_count,
                'rejected_count' => (int) $r->rejected_count,
            ])->values()->all(),
        ]);
    }

    public function quality(Request $request): JsonResponse
    {
        $dbPath = config('database.connections.fasih.database');
        if (! file_exists($dbPath)) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $geo = $this->geoParams($request);

        $query = DB::connection('fasih')
            ->table('assignments as a')
            ->join('users as u', 'u.user_id', '=', 'a.pencacah_user_id')
            ->whereNotNull('a.pencacah_user_id')
            ->selectRaw("
                hex(u.user_id) as uid,
                COALESCE(NULLIF(u.fullname,''), u.email) as nama,
                u.email,
                COUNT(*) as total,
                ROUND(AVG(COALESCE(a.sum_error, 0)), 2) as avg_error,
                ROUND(AVG(COALESCE(a.sum_clean, 0)), 2) as avg_clean,
                ROUND(AVG(COALESCE(a.sum_remark, 0)), 2) as avg_remark,
                SUM(CASE WHEN COALESCE(a.sum_error, 0) > 0 THEN 1 ELSE 0 END) as error_count
            ");

        $this->applyGeoFilter($query, $geo);

        $rows = $query->groupByRaw('u.user_id')
            ->get()
            ->map(function ($r) {
                $total = (int) ($r->total ?: 1);

                return [
                    'uid' => $r->uid,
                    'nama' => $r->nama,
                    'email' => $r->email,
                    'total' => $total,
                    'avg_error' => (float) ($r->avg_error ?? 0),
                    'avg_clean' => (float) ($r->avg_clean ?? 0),
                    'avg_remark' => (float) ($r->avg_remark ?? 0),
                    'error_count' => (int) ($r->error_count ?? 0),
                    'error_pct' => round((int) ($r->error_count ?? 0) / $total * 100, 1),
                ];
            })
            ->sortByDesc('avg_error')
            ->values()
            ->all();

        return response()->json(['data' => $rows, 'total' => count($rows)]);
    }

    public function matrix(Request $request): JsonResponse
    {
        $dbPath = config('database.connections.fasih.database');
        if (! file_exists($dbPath)) {
            return response()->json(['data' => []]);
        }

        $geo = $this->geoParams($request);

        $conditions = [];
        $params = [];
        foreach (['kdkec' => 'c.kdkec', 'kddes' => 'c.kddes', 'kdsls' => 'c.kdsls', 'kdsubsls' => 'c.kdsubsls'] as $key => $col) {
            if ($geo[$key]) {
                $conditions[] = "$col = ?";
                $params[] = $geo[$key];
            }
        }
        $extraWhere = $conditions ? 'AND '.implode(' AND ', $conditions) : '';

        $turnaroundRows = DB::connection('fasih')->select("
            SELECT
                hex(c.pencacah_user_id) as uid,
                ROUND(AVG((julianday(c.change_date) - julianday(d.last_draft)) * 24 * 60), 1) as avg_minutes,
                COUNT(*) as sample_count
            FROM assignment_status_changes c
            JOIN (
                SELECT assignment_id, MAX(change_date) as last_draft
                FROM assignment_status_changes
                WHERE to_status_id = 0
                GROUP BY assignment_id
            ) d ON d.assignment_id = c.assignment_id
              AND c.change_date > d.last_draft
            WHERE c.to_status_id = 1
              AND c.pencacah_user_id IS NOT NULL
              $extraWhere
            GROUP BY c.pencacah_user_id
            HAVING sample_count >= 3
        ", $params);

        $qualityQuery = DB::connection('fasih')
            ->table('assignments as a')
            ->join('users as u', 'u.user_id', '=', 'a.pencacah_user_id')
            ->whereNotNull('a.pencacah_user_id')
            ->selectRaw("
                hex(u.user_id) as uid,
                COALESCE(NULLIF(u.fullname,''), u.email) as nama,
                COUNT(*) as total,
                SUM(CASE WHEN a.assignment_status_id = 3 THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN a.assignment_status_id IN (1,2,3) THEN 1 ELSE 0 END) as reviewed
            ");

        $this->applyGeoFilter($qualityQuery, $geo);

        $qualityMap = collect($qualityQuery->groupByRaw('u.user_id')->get())
            ->keyBy('uid')
            ->map(fn ($r) => [
                'uid' => $r->uid,
                'nama' => $r->nama,
                'total' => (int) $r->total,
                'rejection_rate' => (int) $r->reviewed > 0
                    ? round((int) $r->rejected / (int) $r->reviewed * 100, 1)
                    : 0.0,
            ]);

        $result = collect($turnaroundRows)
            ->filter(fn ($r) => isset($qualityMap[$r->uid]))
            ->map(fn ($r) => [
                'uid' => $r->uid,
                'nama' => $qualityMap[$r->uid]['nama'],
                'avg_minutes' => (float) ($r->avg_minutes ?? 0),
                'sample_count' => (int) $r->sample_count,
                'rejection_rate' => $qualityMap[$r->uid]['rejection_rate'],
                'total' => $qualityMap[$r->uid]['total'],
            ])
            ->values()
            ->all();

        return response()->json(['data' => $result]);
    }

    public function gelombang(Request $request): JsonResponse
    {
        $dbPath = config('database.connections.fasih.database');
        if (! file_exists($dbPath)) {
            return response()->json([]);
        }

        $validGroups = ['gelombang', 'kelas', 'tc'];
        $groupBy = in_array($request->input('group_by'), $validGroups)
            ? $request->input('group_by') : 'gelombang';

        $latestSnap = DB::connection('fasih')
            ->table('progress_pencacah')
            ->max('snapshot_at');

        if (! $latestSnap) {
            return response()->json([]);
        }

        return response()->json(
            DB::connection('fasih')
                ->table('progress_pencacah')
                ->where('snapshot_at', $latestSnap)
                ->whereNotNull($groupBy)
                ->where($groupBy, '!=', '')
                ->selectRaw("
                    TRIM($groupBy) as group_label,
                    COUNT(DISTINCT username) as total_pencacah,
                    SUM(region_total) as total_assignment,
                    SUM(\"OPEN\") as total_open,
                    SUM(\"DRAFT\") as total_draft,
                    SUM(\"SUBMITTED BY Pencacah\") as total_submitted,
                    SUM(\"APPROVED BY Pengawas\") as total_approved,
                    SUM(\"REJECTED BY Pengawas\") as total_rejected
                ")
                ->groupByRaw("TRIM($groupBy)")
                ->orderByRaw("TRIM($groupBy)")
                ->get()
                ->map(function ($r) {
                    $total = (int) ($r->total_assignment ?: 1);
                    $open = (int) ($r->total_open ?: 0);
                    $draft = (int) ($r->total_draft ?: 0);
                    $approved = (int) ($r->total_approved ?: 0);

                    return [
                        'label' => $r->group_label,
                        'total_pencacah' => (int) $r->total_pencacah,
                        'total_assignment' => $total,
                        'progress_pct' => round(($total - $open - $draft) / $total * 100, 1),
                        'approved_pct' => round($approved / $total * 100, 1),
                        'submitted' => (int) ($r->total_submitted ?? 0),
                        'approved' => (int) ($r->total_approved ?? 0),
                        'rejected' => (int) ($r->total_rejected ?? 0),
                    ];
                })
                ->values()
                ->all()
        );
    }
}
