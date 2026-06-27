<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StatistikController extends Controller
{
    /** @return literal-string */
    private function groupExpression(string $groupBy): string
    {
        return match ($groupBy) {
            'kelas' => 'TRIM(kelas)',
            'tc' => 'TRIM(tc)',
            default => 'TRIM(gelombang)',
        };
    }

    // Standard normal CDF approximation (Abramowitz & Stegun 26.2.17)
    private function normalCdf(float $z): float
    {
        if ($z < -6) {
            return 0.0;
        }
        if ($z > 6) {
            return 1.0;
        }
        $t = 1.0 / (1.0 + 0.2316419 * abs($z));
        $poly = $t * (0.319381530
            + $t * (-0.356563782
            + $t * (1.781477937
            + $t * (-1.821255978
            + $t * 1.330274429))));
        $pdf = exp(-$z * $z / 2.0) / sqrt(2 * M_PI);
        $p = 1.0 - $pdf * $poly;

        return $z >= 0 ? $p : 1.0 - $p;
    }

    // Chi-squared p-value approximation (Wilson-Hilferty transformation)
    private function chi2Pvalue(float $chi2, int $df): float
    {
        if ($chi2 <= 0 || $df <= 0) {
            return 1.0;
        }
        $z = (pow($chi2 / $df, 1 / 3.0) - (1.0 - 2.0 / (9.0 * $df))) / sqrt(2.0 / (9.0 * $df));

        return 1.0 - $this->normalCdf($z);
    }

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

        return Inertia::render('Statistik', [
            'db_ready' => file_exists($dbPath),
            'kec_options' => $kecOptions,
        ]);
    }

    // Estimasi proporsi + CI 95% per kecamatan / gelombang / kelas / tc
    public function proporsi(Request $request): JsonResponse
    {
        $dbPath = config('database.connections.fasih.database');
        if (! file_exists($dbPath)) {
            return response()->json([]);
        }

        $groupBy = in_array($request->input('group_by'), ['kecamatan', 'gelombang', 'kelas', 'tc'])
            ? $request->input('group_by') : 'kecamatan';
        $groupExpression = $this->groupExpression($groupBy);

        if ($groupBy === 'kecamatan') {
            $rows = DB::connection('fasih')
                ->table('assignments as a')
                ->leftJoin('wilayah as w', function ($j) {
                    $j->on('w.full_code', '=', 'a.kdkec')->where('w.level', 3);
                })
                ->selectRaw('
                    a.kdkec as group_code,
                    COALESCE(w.name, a.kdkec) as group_label,
                    COUNT(*) as total,
                    SUM(CASE WHEN a.assignment_status_id = 2 THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN a.assignment_status_id = 1 THEN 1 ELSE 0 END) as submitted,
                    SUM(CASE WHEN a.assignment_status_id = 3 THEN 1 ELSE 0 END) as rejected
                ')
                ->groupBy('a.kdkec')
                ->orderBy('group_label')
                ->get();
        } else {
            $latestSnap = DB::connection('fasih')->table('progress_pencacah')->max('snapshot_at');
            $rows = DB::connection('fasih')
                ->table('progress_pencacah')
                ->where('snapshot_at', $latestSnap ?? '')
                ->whereNotNull($groupBy)
                ->where($groupBy, '!=', '')
                ->selectRaw("
                    $groupExpression as group_code,
                    $groupExpression as group_label,
                    SUM(region_total) as total,
                    SUM(\"APPROVED BY Pengawas\") as approved,
                    SUM(\"SUBMITTED BY Pencacah\") as submitted,
                    SUM(\"REJECTED BY Pengawas\") as rejected
                ")
                ->groupByRaw($groupExpression)
                ->orderByRaw($groupExpression)
                ->get();
        }

        return response()->json($rows->map(function ($r) {
            $n = max(1, (int) $r->total);
            $approved = (int) ($r->approved ?? 0);
            $submitted = (int) ($r->submitted ?? 0);
            $rejected = (int) ($r->rejected ?? 0);
            $p = $approved / $n;
            $margin = 1.96 * sqrt($p * (1 - $p) / $n);

            return [
                'group_code' => $r->group_code,
                'group_label' => $r->group_label,
                'total' => $n,
                'approved' => $approved,
                'submitted' => $submitted,
                'rejected' => $rejected,
                'p_approved' => round($p * 100, 2),
                'ci_lower' => round(max(0, $p - $margin) * 100, 2),
                'ci_upper' => round(min(1, $p + $margin) * 100, 2),
                'margin_of_error' => round($margin * 100, 2),
            ];
        })->values()->all());
    }

    // Z-test dua proporsi
    public function komparasi(Request $request): JsonResponse
    {
        $dbPath = config('database.connections.fasih.database');
        if (! file_exists($dbPath)) {
            return response()->json(['error' => 'DB not ready']);
        }

        $validGroups = ['kecamatan', 'gelombang', 'kelas', 'tc'];
        $groupBy = in_array($request->input('group_by'), $validGroups)
            ? $request->input('group_by') : 'kecamatan';
        $groupExpression = $this->groupExpression($groupBy);
        $groupA = trim($request->input('group_a', ''));
        $groupB = trim($request->input('group_b', ''));

        if (! $groupA || ! $groupB || $groupA === $groupB) {
            return response()->json(['error' => 'Pilih dua kelompok yang berbeda']);
        }

        $getData = function (string $group) use ($groupBy, $groupExpression) {
            if ($groupBy === 'kecamatan') {
                return DB::connection('fasih')
                    ->table('assignments')
                    ->where('kdkec', $group)
                    ->selectRaw('COUNT(*) as total, SUM(CASE WHEN assignment_status_id = 2 THEN 1 ELSE 0 END) as approved')
                    ->first();
            }
            $snap = DB::connection('fasih')->table('progress_pencacah')->max('snapshot_at');

            return DB::connection('fasih')
                ->table('progress_pencacah')
                ->where('snapshot_at', $snap ?? '')
                ->whereRaw("$groupExpression = ?", [$group])
                ->selectRaw('SUM(region_total) as total, SUM("APPROVED BY Pengawas") as approved')
                ->first();
        };

        $a = $getData($groupA);
        $b = $getData($groupB);

        $n1 = max(1, (int) ($a->total ?? 0));
        $n2 = max(1, (int) ($b->total ?? 0));
        $x1 = (int) ($a->approved ?? 0);
        $x2 = (int) ($b->approved ?? 0);

        $p1 = $x1 / $n1;
        $p2 = $x2 / $n2;
        $pPool = ($x1 + $x2) / ($n1 + $n2);
        $se = sqrt($pPool * (1 - $pPool) * (1 / $n1 + 1 / $n2));
        $z = $se > 0 ? ($p1 - $p2) / $se : 0.0;
        $pValue = 2 * (1 - $this->normalCdf(abs($z)));

        return response()->json([
            'group_a' => ['code' => $groupA, 'n' => $n1, 'approved' => $x1, 'p' => round($p1 * 100, 2)],
            'group_b' => ['code' => $groupB, 'n' => $n2, 'approved' => $x2, 'p' => round($p2 * 100, 2)],
            'z' => round($z, 4),
            'p_value' => round($pValue, 4),
            'significant' => $pValue < 0.05,
            'interpretasi' => $pValue < 0.05
                ? 'Ada perbedaan signifikan (p='.round($pValue, 4).') pada tingkat kepercayaan 95%.'
                : 'Tidak ada perbedaan signifikan (p='.round($pValue, 4).') pada tingkat kepercayaan 95%.',
        ]);
    }

    // Chi-squared test asosiasi variabel grup × status
    public function chi2(Request $request): JsonResponse
    {
        $dbPath = config('database.connections.fasih.database');
        if (! file_exists($dbPath)) {
            return response()->json(['error' => 'DB not ready']);
        }

        $validGroups = ['gelombang', 'kelas', 'tc'];
        $groupBy = in_array($request->input('group_by'), $validGroups)
            ? $request->input('group_by') : 'gelombang';
        $groupExpression = $this->groupExpression($groupBy);

        $latestSnap = DB::connection('fasih')->table('progress_pencacah')->max('snapshot_at');

        $rows = DB::connection('fasih')
            ->table('progress_pencacah')
            ->where('snapshot_at', $latestSnap ?? '')
            ->whereNotNull($groupBy)
            ->where($groupBy, '!=', '')
            ->selectRaw("
                $groupExpression as grp,
                SUM(\"APPROVED BY Pengawas\") as approved,
                SUM(\"SUBMITTED BY Pencacah\") as submitted,
                SUM(\"REJECTED BY Pengawas\") as rejected,
                SUM(\"OPEN\") as open_count,
                SUM(region_total) as total
            ")
            ->groupByRaw($groupExpression)
            ->orderByRaw($groupExpression)
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['error' => 'Tidak ada data']);
        }

        $groups = $rows->pluck('grp')->all();
        $colKeys = ['approved', 'submitted', 'rejected', 'open_count'];
        $colLabels = ['Approved', 'Submitted', 'Rejected', 'Open'];

        $observed = [];
        $colSums = array_fill(0, count($colKeys), 0);
        $rowSums = [];

        foreach ($rows as $i => $r) {
            $rowSums[$i] = 0;
            foreach ($colKeys as $j => $col) {
                $v = (int) ($r->$col ?? 0);
                $observed[$i][$j] = $v;
                $colSums[$j] += $v;
                $rowSums[$i] += $v;
            }
        }

        $grandTotal = array_sum($colSums);
        if ($grandTotal <= 0) {
            return response()->json(['error' => 'Tidak ada data']);
        }

        $chi2val = 0.0;
        foreach ($observed as $i => $row) {
            foreach ($row as $j => $obs) {
                $expected = $rowSums[$i] * $colSums[$j] / $grandTotal;
                if ($expected > 0) {
                    $chi2val += ($obs - $expected) ** 2 / $expected;
                }
            }
        }

        $df = (count($groups) - 1) * (count($colKeys) - 1);
        $pValue = $this->chi2Pvalue($chi2val, max(1, $df));

        $table = [];
        foreach ($rows as $i => $r) {
            $rowData = ['grp' => $groups[$i]];
            foreach ($colKeys as $j => $col) {
                $rowData[$col] = $observed[$i][$j];
            }
            $rowData['total'] = $rowSums[$i];
            $table[] = $rowData;
        }

        $chi2round = round($chi2val, 4);
        $pRound = round($pValue, 4);

        return response()->json([
            'group_by' => $groupBy,
            'col_labels' => $colLabels,
            'col_keys' => $colKeys,
            'table' => $table,
            'col_sums' => array_combine($colKeys, $colSums),
            'grand_total' => $grandTotal,
            'chi2' => $chi2round,
            'df' => $df,
            'p_value' => $pRound,
            'significant' => $pValue < 0.05,
            'interpretasi' => $pValue < 0.05
                ? "Ada asosiasi signifikan antara {$groupBy} dan status penugasan (χ²={$chi2round}, df={$df}, p={$pRound})."
                : "Tidak ada asosiasi signifikan antara {$groupBy} dan status penugasan (χ²={$chi2round}, df={$df}, p={$pRound}).",
        ]);
    }

    // Pearson correlation: avg_error vs rejection_rate per pencacah
    public function korelasi(): JsonResponse
    {
        $dbPath = config('database.connections.fasih.database');
        if (! file_exists($dbPath)) {
            return response()->json(['error' => 'DB not ready']);
        }

        $rows = DB::connection('fasih')
            ->table('assignments as a')
            ->join('users as u', 'u.user_id', '=', 'a.pencacah_user_id')
            ->whereNotNull('a.pencacah_user_id')
            ->selectRaw("
                COALESCE(NULLIF(u.fullname,''), u.email) as nama,
                COUNT(*) as total,
                ROUND(AVG(COALESCE(a.sum_error, 0)), 3) as avg_error,
                SUM(CASE WHEN a.assignment_status_id = 3 THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN a.assignment_status_id IN (1,2,3) THEN 1 ELSE 0 END) as reviewed
            ")
            ->groupByRaw('u.user_id')
            ->having('reviewed', '>', 0)
            ->get();

        $points = $rows->filter(fn ($r) => (int) $r->total >= 5)
            ->map(function ($r) {
                $reviewed = (int) $r->reviewed;

                return [
                    'nama' => $r->nama,
                    'total' => (int) $r->total,
                    'avg_error' => (float) $r->avg_error,
                    'rejection_rate' => $reviewed > 0 ? round((int) $r->rejected / $reviewed * 100, 2) : 0.0,
                ];
            })->values();

        $n = $points->count();
        if ($n < 3) {
            return response()->json([
                'points' => $points, 'n' => $n, 'r' => null, 'p_value' => null,
                'interpretasi' => 'Data tidak cukup untuk menghitung korelasi (minimal 5 pencacah).',
            ]);
        }

        $xs = $points->pluck('avg_error')->all();
        $ys = $points->pluck('rejection_rate')->all();
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;

        $cov = $varX = $varY = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $xs[$i] - $mx;
            $dy = $ys[$i] - $my;
            $cov += $dx * $dy;
            $varX += $dx * $dx;
            $varY += $dy * $dy;
        }

        $r = ($varX > 0 && $varY > 0) ? $cov / sqrt($varX * $varY) : 0.0;
        $t = $r * sqrt($n - 2) / sqrt(max(1e-10, 1 - $r * $r));
        $pValue = 2 * (1 - $this->normalCdf(abs($t)));

        $strength = match (true) {
            abs($r) >= 0.7 => 'kuat',
            abs($r) >= 0.4 => 'sedang',
            abs($r) >= 0.2 => 'lemah',
            default => 'sangat lemah',
        };
        $direction = $r >= 0 ? 'positif' : 'negatif';
        $rRound = round($r, 4);
        $pRound = round($pValue, 4);

        return response()->json([
            'points' => $points->all(),
            'n' => $n,
            'r' => $rRound,
            'r2' => round($r * $r, 4),
            't' => round($t, 4),
            'p_value' => $pRound,
            'significant' => $pValue < 0.05,
            'interpretasi' => "Korelasi {$direction} {$strength} (r={$rRound}) antara rata-rata error dan tingkat penolakan per pencacah. "
                .($pValue < 0.05 ? 'Signifikan secara statistik.' : 'Tidak signifikan secara statistik.')
                ." (n={$n} pencacah, p={$pRound})",
        ]);
    }

    public function bangunanKosong(): JsonResponse
    {
        $dbPath = config('database.connections.fasih.database');
        if (! file_exists($dbPath)) {
            return response()->json(['summary' => [], 'per_kec' => []]);
        }

        $rows = DB::connection('fasih')
            ->table('assignments as a')
            ->leftJoin('wilayah as w', function ($j) {
                $j->on('w.full_code', '=', 'a.kdkec')->where('w.level', 3);
            })
            ->whereRaw("TRIM(a.data1) IN ('BANGUNAN KOSONG', 'RUMAH KOSONG')")
            ->selectRaw('
                TRIM(a.data1) as kategori,
                COALESCE(w.name, a.kdkec) as nmkec,
                a.kdkec,
                COUNT(*) as total,
                SUM(CASE WHEN a.assignment_status_id = 0 THEN 1 ELSE 0 END) as draft,
                SUM(CASE WHEN a.assignment_status_id = 1 THEN 1 ELSE 0 END) as submitted,
                SUM(CASE WHEN a.assignment_status_id = 2 THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN a.assignment_status_id = 3 THEN 1 ELSE 0 END) as rejected
            ')
            ->groupByRaw('TRIM(a.data1), a.kdkec')
            ->orderBy('a.kdkec')
            ->get();

        $byKategori = $rows->groupBy('kategori')->map(fn ($g) => [
            'kategori' => $g->first()->kategori,
            'total' => $g->sum('total'),
            'draft' => $g->sum('draft'),
            'submitted' => $g->sum('submitted'),
            'approved' => $g->sum('approved'),
            'rejected' => $g->sum('rejected'),
        ])->values()->all();

        $perKec = $rows->groupBy('kdkec')->map(fn ($g) => [
            'nmkec' => $g->first()->nmkec,
            'kdkec' => $g->first()->kdkec,
            'bangunan_kosong' => $g->where('kategori', 'BANGUNAN KOSONG')->sum('total'),
            'rumah_kosong' => $g->where('kategori', 'RUMAH KOSONG')->sum('total'),
            'total' => $g->sum('total'),
            'submitted' => $g->sum('submitted'),
            'approved' => $g->sum('approved'),
            'draft' => $g->sum('draft'),
        ])->sortByDesc('total')->values()->all();

        return response()->json(['summary' => $byKategori, 'per_kec' => $perKec]);
    }
}
