<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PenugasanController extends Controller
{
    public function index(): Response
    {
        $dbPath = config('database.connections.fasih.database');

        $wilayah = [];
        $pengawasOptions = [];

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

            $pengawasOptions = DB::connection('fasih')
                ->table('users')
                ->where('is_pencacah', 0)
                ->select('email', 'fullname')
                ->orderBy('fullname')
                ->get()
                ->map(fn ($u) => [
                    'email' => $u->email,
                    'nama' => $u->fullname ?: $u->email,
                ])
                ->values()
                ->all();
        }

        return Inertia::render('Penugasan', [
            'db_ready' => file_exists($dbPath),
            'wilayah' => $wilayah,
            'pengawas_options' => $pengawasOptions,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $dbPath = config('database.connections.fasih.database');
        if (! file_exists($dbPath)) {
            return response()->json(['data' => [], 'total' => 0, 'per_page' => 20]);
        }

        $validPerPage = [10, 20, 50];
        $perPage = in_array((int) $request->input('per_page', 20), $validPerPage)
            ? (int) $request->input('per_page', 20) : 20;
        $page = max(1, (int) $request->input('page', 1));
        $offset = ($page - 1) * $perPage;

        $statusId = $request->input('status', '');
        $kdkec = preg_replace('/[^0-9]/', '', $request->input('kdkec', ''));
        $kddes = preg_replace('/[^0-9]/', '', $request->input('kddes', ''));
        $kdsls = preg_replace('/[^0-9]/', '', $request->input('kdsls', ''));
        $kdsubsls = preg_replace('/[^0-9]/', '', $request->input('kdsubsls', ''));
        $pengawasEmail = trim($request->input('pengawas', ''));
        $search = trim($request->input('search', ''));

        $base = DB::connection('fasih')
            ->table('assignments as a')
            ->leftJoin('users as pnc', 'pnc.user_id', '=', 'a.pencacah_user_id')
            ->leftJoin('users as pgw', 'pgw.user_id', '=', 'a.pengawas_user_id')
            ->leftJoin('assignment_statuses as s', 's.id', '=', 'a.assignment_status_id')
            ->leftJoin('wilayah as w3', function ($j) {
                $j->on('w3.full_code', '=', 'a.kdkec')->where('w3.level', 3);
            })
            ->leftJoin('wilayah as w4', function ($j) {
                $j->on('w4.full_code', '=', 'a.kddes')->where('w4.level', 4);
            });

        if ($statusId !== '') {
            $base->where('a.assignment_status_id', (int) $statusId);
        }
        if ($kdkec) {
            $base->where('a.kdkec', $kdkec);
        }
        if ($kddes) {
            $base->where('a.kddes', $kddes);
        }
        if ($kdsls) {
            $base->where('a.kdsls', $kdsls);
        }
        if ($kdsubsls) {
            $base->where('a.kdsubsls', $kdsubsls);
        }
        if ($pengawasEmail) {
            $base->where('pgw.email', $pengawasEmail);
        }
        if ($search) {
            $base->where('a.code_identity', 'like', '%'.$search.'%');
        }

        $total = (clone $base)->count();

        $rows = (clone $base)
            ->selectRaw("
                printf('%s-%s-%s-%s-%s',
                    lower(hex(substr(a.assignment_id,1,4))),
                    lower(hex(substr(a.assignment_id,5,2))),
                    lower(hex(substr(a.assignment_id,7,2))),
                    lower(hex(substr(a.assignment_id,9,2))),
                    lower(hex(substr(a.assignment_id,11,6)))
                ) as assignment_id,
                a.code_identity,
                COALESCE(NULLIF(pnc.fullname,''), pnc.email) as pencacah_nama,
                pnc.email as pencacah_email,
                COALESCE(NULLIF(pgw.fullname,''), pgw.email) as pengawas_nama,
                s.alias as status,
                a.assignment_status_id,
                a.date_modified,
                w3.name as nmkec, a.kdkec,
                w4.name as nmdes, a.kddes
            ")
            ->orderByDesc('a.date_modified')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return response()->json([
            'data' => $rows,
            'total' => $total,
            'per_page' => $perPage,
        ]);
    }

    public function mangkrak(Request $request): JsonResponse
    {
        $dbPath = config('database.connections.fasih.database');
        if (! file_exists($dbPath)) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $threshold = max(1, min(30, (int) $request->input('threshold_days', 7)));
        $kdkec = preg_replace('/[^0-9]/', '', $request->input('kdkec', ''));
        $kddes = preg_replace('/[^0-9]/', '', $request->input('kddes', ''));
        $kdsls = preg_replace('/[^0-9]/', '', $request->input('kdsls', ''));
        $kdsubsls = preg_replace('/[^0-9]/', '', $request->input('kdsubsls', ''));
        $pengawasEmail = trim($request->input('pengawas', ''));

        $query = DB::connection('fasih')
            ->table('assignments as a')
            ->leftJoin('users as pnc', 'pnc.user_id', '=', 'a.pencacah_user_id')
            ->leftJoin('users as pgw', 'pgw.user_id', '=', 'a.pengawas_user_id')
            ->leftJoin('assignment_statuses as s', 's.id', '=', 'a.assignment_status_id')
            ->leftJoin('wilayah as w3', function ($j) {
                $j->on('w3.full_code', '=', 'a.kdkec')->where('w3.level', 3);
            })
            ->leftJoin('wilayah as w4', function ($j) {
                $j->on('w4.full_code', '=', 'a.kddes')->where('w4.level', 4);
            })
            ->where('a.assignment_status_id', '!=', 2)
            ->whereRaw("julianday('now') - julianday(a.date_modified) >= ?", [$threshold]);

        if ($kdkec) {
            $query->where('a.kdkec', $kdkec);
        }
        if ($kddes) {
            $query->where('a.kddes', $kddes);
        }
        if ($kdsls) {
            $query->where('a.kdsls', $kdsls);
        }
        if ($kdsubsls) {
            $query->where('a.kdsubsls', $kdsubsls);
        }
        if ($pengawasEmail) {
            $query->where('pgw.email', $pengawasEmail);
        }

        $total = (clone $query)->count();

        $rows = (clone $query)
            ->selectRaw("
                printf('%s-%s-%s-%s-%s',
                    lower(hex(substr(a.assignment_id,1,4))),
                    lower(hex(substr(a.assignment_id,5,2))),
                    lower(hex(substr(a.assignment_id,7,2))),
                    lower(hex(substr(a.assignment_id,9,2))),
                    lower(hex(substr(a.assignment_id,11,6)))
                ) as assignment_id,
                a.code_identity,
                COALESCE(NULLIF(pnc.fullname,''), pnc.email) as pencacah_nama,
                pnc.email as pencacah_email,
                COALESCE(NULLIF(pgw.fullname,''), pgw.email) as pengawas_nama,
                s.alias as status,
                a.assignment_status_id,
                a.date_modified,
                CAST(julianday('now') - julianday(a.date_modified) AS INTEGER) as days_stale,
                w3.name as nmkec, a.kdkec,
                w4.name as nmdes, a.kddes
            ")
            ->orderByRaw("julianday('now') - julianday(a.date_modified) DESC")
            ->limit(200)
            ->get();

        return response()->json(['data' => $rows, 'total' => $total]);
    }

    public function history(Request $request): JsonResponse
    {
        $dbPath = config('database.connections.fasih.database');
        if (! file_exists($dbPath)) {
            return response()->json([]);
        }

        $assignmentId = $request->input('id', '');

        if (! preg_match('/^[0-9a-f-]+$/i', $assignmentId)) {
            return response()->json([]);
        }

        $rows = DB::connection('fasih')
            ->table('assignment_status_changes_full')
            ->where('assignment_id', $assignmentId)
            ->select('id', 'from_status', 'to_status', 'change_date', 'pencacah_email', 'pengawas_email')
            ->orderBy('change_date')
            ->get();

        // Dedup consecutive rows with same to_status
        $deduped = [];
        $prevStatus = null;
        foreach ($rows as $row) {
            if ($row->to_status !== $prevStatus) {
                $deduped[] = $row;
                $prevStatus = $row->to_status;
            }
        }

        return response()->json($deduped);
    }
}
