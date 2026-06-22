<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PenugasanController extends Controller
{
    private const PER_PAGE = 50;

    public function index(): Response
    {
        $dbPath = config('database.connections.fasih.database');

        $kecOptions = [];
        if (file_exists($dbPath)) {
            $kecOptions = DB::connection('fasih')
                ->table('wilayah')
                ->where('level', 3)
                ->select('full_code as code', 'name')
                ->orderBy('name')
                ->get()
                ->all();
        }

        return Inertia::render('Penugasan', [
            'db_ready' => file_exists($dbPath),
            'kec_options' => $kecOptions,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $dbPath = config('database.connections.fasih.database');
        if (! file_exists($dbPath)) {
            return response()->json(['data' => [], 'total' => 0, 'per_page' => self::PER_PAGE]);
        }

        $page = max(1, (int) $request->input('page', 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $statusId = $request->input('status', '');
        $kdkec = preg_replace('/[^0-9]/', '', $request->input('kdkec', ''));
        $kddes = preg_replace('/[^0-9]/', '', $request->input('kddes', ''));
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
            ->limit(self::PER_PAGE)
            ->offset($offset)
            ->get();

        return response()->json([
            'data' => $rows,
            'total' => $total,
            'per_page' => self::PER_PAGE,
        ]);
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

        return response()->json($rows);
    }
}
