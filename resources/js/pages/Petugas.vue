<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useDark } from '@vueuse/core';
import { ref, computed, watch, onMounted } from 'vue';
import VueApexCharts from 'vue3-apexcharts';

interface WilayahNode {
    uuid: string;
    level: number;
    code: string;
    name: string;
    parent_uuid: string | null;
}

const props = defineProps<{
    db_ready: boolean;
    wilayah: Record<string, WilayahNode[]>;
}>();

// ── types ─────────────────────────────────────────────────────────────────
interface PetugasRow {
    uid: string; nama: string; email: string;
    total: number; draft: number; submitted: number;
    approved: number; rejected: number;
    rejection_rate: number; progress_pct: number;
}
interface TurnaroundPencacah { uid: string; nama: string; avg_minutes: number; sample_count: number; }
interface TurnaroundPengawas { uid: string; nama: string; avg_minutes: number; sample_count: number; approved_count: number; rejected_count: number; }
interface QualityRow { uid: string; nama: string; email: string; total: number; avg_error: number; avg_clean: number; avg_remark: number; error_count: number; error_pct: number; }
interface GelombangRow { label: string; total_pencacah: number; total_assignment: number; progress_pct: number; approved_pct: number; submitted: number; approved: number; rejected: number; }

// ── wilayah filter state ───────────────────────────────────────────────────
const filterKec    = ref('');
const filterDes    = ref('');
const filterSls    = ref('');
const filterSubsls = ref('');

const w = props.wilayah as Record<string, WilayahNode[]>;
const kecOptions    = computed(() => w[3] ?? []);
const desOptions    = computed(() => {
    if (!filterKec.value) {
return w[4] ?? [];
}

    const kecNode = kecOptions.value.find(k => k.code === filterKec.value);

    if (!kecNode) {
return w[4] ?? [];
}

    return (w[4] ?? []).filter(d => d.parent_uuid === kecNode.uuid);
});
const slsOptions    = computed(() => {
    if (!filterDes.value) {
return [];
}

    const desNode = (w[4] ?? []).find(d => d.code === filterDes.value);

    if (!desNode) {
return [];
}

    return (w[5] ?? []).filter(s => s.parent_uuid === desNode.uuid);
});
const subslsOptions = computed(() => {
    if (!filterSls.value) {
return [];
}

    const slsNode = (w[5] ?? []).find(s => s.code === filterSls.value);

    if (!slsNode) {
return [];
}

    return (w[6] ?? []).filter(ss => ss.parent_uuid === slsNode.uuid);
});

watch(filterKec,    () => {
 filterDes.value = ''; filterSls.value = ''; filterSubsls.value = ''; 
});
watch(filterDes,    () => {
 filterSls.value = ''; filterSubsls.value = ''; 
});
watch(filterSls,    () => {
 filterSubsls.value = ''; 
});

// ── tab & loading state ────────────────────────────────────────────────────
const isDark    = useDark();
const activeTab = ref<'pencacah' | 'turnaround' | 'quality' | 'gelombang' | 'matrix' | 'leaderboard'>('pencacah');
const loading   = ref(false);

// ── pencacah tab ───────────────────────────────────────────────────────────
const pencacahAll  = ref<PetugasRow[]>([]);
const sortKey      = ref<keyof PetugasRow>('total');
const sortDir      = ref<'asc' | 'desc'>('desc');
const pencacahPage = ref(1);
const pencacahPer  = ref(20);

const sortedPencacah = computed(() =>
    [...pencacahAll.value].sort((a, b) => {
        const av = a[sortKey.value] as number, bv = b[sortKey.value] as number;

        return sortDir.value === 'desc' ? bv - av : av - bv;
    }),
);
const paginatedPencacah = computed(() => {
    const s = (pencacahPage.value - 1) * pencacahPer.value;

    return sortedPencacah.value.slice(s, s + pencacahPer.value);
});
const pencacahPages = computed(() => Math.ceil(pencacahAll.value.length / pencacahPer.value));

function setSort(key: keyof PetugasRow) {
    if (sortKey.value === key) {
sortDir.value = sortDir.value === 'desc' ? 'asc' : 'desc';
} else {
 sortKey.value = key; sortDir.value = 'desc'; 
}
}

// ── turnaround tab ─────────────────────────────────────────────────────────
const turnaroundPencacah = ref<TurnaroundPencacah[]>([]);
const turnaroundPengawas = ref<TurnaroundPengawas[]>([]);
const turnaroundView     = ref<'pencacah' | 'pengawas'>('pencacah');
const turnaroundPage     = ref(1);
const turnaroundPer      = ref(20);

const turnaroundData = computed(() =>
    turnaroundView.value === 'pencacah' ? turnaroundPencacah.value : turnaroundPengawas.value,
);
const paginatedTurnaround = computed(() => {
    const s = (turnaroundPage.value - 1) * turnaroundPer.value;

    return turnaroundData.value.slice(s, s + turnaroundPer.value);
});
const turnaroundPages = computed(() => Math.ceil(turnaroundData.value.length / turnaroundPer.value));

// ── quality tab ────────────────────────────────────────────────────────────
const qualityAll      = ref<QualityRow[]>([]);
const qualitySortKey  = ref<keyof QualityRow>('avg_error');
const qualityPage     = ref(1);
const qualityPer      = ref(20);

const sortedQuality = computed(() =>
    [...qualityAll.value].sort((a, b) => (b[qualitySortKey.value] as number) - (a[qualitySortKey.value] as number)),
);
const paginatedQuality = computed(() => {
    const s = (qualityPage.value - 1) * qualityPer.value;

    return sortedQuality.value.slice(s, s + qualityPer.value);
});
const qualityPages = computed(() => Math.ceil(qualityAll.value.length / qualityPer.value));

// ── gelombang tab ──────────────────────────────────────────────────────────
const gelombangList    = ref<GelombangRow[]>([]);
const gelombangGroupBy = ref<'gelombang' | 'kelas' | 'tc'>('gelombang');

// ── matrix tab ─────────────────────────────────────────────────────────────
interface MatrixRow { uid: string; nama: string; avg_minutes: number; rejection_rate: number; total: number; sample_count: number; }
const matrixData = ref<MatrixRow[]>([]);
const matrixPage = ref(1);
const matrixPer  = ref(20);
const matrixPages = computed(() => Math.ceil(matrixData.value.length / matrixPer.value));
const paginatedMatrix = computed(() => {
    const s = (matrixPage.value - 1) * matrixPer.value;
    return matrixData.value.slice(s, s + matrixPer.value);
});

// ── leaderboard tab ────────────────────────────────────────────────────────
interface LeaderboardRow { rank: number; uid: string; nama: string; email: string; score: number; progress_pct: number; rejection_rate: number; total: number; approved: number; }
const leaderboardPage = ref(1);
const leaderboardPer  = ref(20);
const leaderboardData = computed<LeaderboardRow[]>(() =>
    [...pencacahAll.value]
        .filter(r => r.total >= 1)
        .map(r => ({
            ...r,
            score: Math.round(r.progress_pct * 0.5 + Math.max(0, 100 - r.rejection_rate) * 0.3 + (r.total > 0 ? r.approved / r.total * 100 : 0) * 0.2),
        }))
        .sort((a, b) => b.score - a.score)
        .map((r, i) => ({ rank: i + 1, ...r }))
);
const leaderboardPages = computed(() => Math.ceil(leaderboardData.value.length / leaderboardPer.value));
const paginatedLeaderboard = computed(() => {
    const s = (leaderboardPage.value - 1) * leaderboardPer.value;
    return leaderboardData.value.slice(s, s + leaderboardPer.value);
});

// ── fetch ─────────────────────────────────────────────────────────────────
function geoParams(): string {
    const p = new URLSearchParams();

    if (filterKec.value)    {
p.set('kdkec',    filterKec.value);
}

    if (filterDes.value)    {
p.set('kddes',    filterDes.value);
}

    if (filterSls.value)    {
p.set('kdsls',    filterSls.value);
}

    if (filterSubsls.value) {
p.set('kdsubsls', filterSubsls.value);
}

    return p.toString();
}

const H = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

async function fetchTab(tab: typeof activeTab.value, force = false) {
    if (!props.db_ready) {
return;
}

    loading.value = true;

    try {
        if (tab === 'pencacah') {
            if (!force && pencacahAll.value.length) { loading.value = false; return; }
            const r = await fetch(`/api/petugas/list?${geoParams()}`, { headers: H });
            const d = await r.json();
            pencacahAll.value  = d.data ?? [];
            pencacahPage.value = 1;
        } else if (tab === 'turnaround') {
            if (!force && turnaroundPencacah.value.length) { loading.value = false; return; }
            const r = await fetch(`/api/petugas/turnaround?${geoParams()}`, { headers: H });
            const d = await r.json();
            turnaroundPencacah.value = d.pencacah ?? [];
            turnaroundPengawas.value = d.pengawas ?? [];
            turnaroundPage.value = 1;
        } else if (tab === 'quality') {
            if (!force && qualityAll.value.length) { loading.value = false; return; }
            const r = await fetch(`/api/petugas/quality?${geoParams()}`, { headers: H });
            const d = await r.json();
            qualityAll.value  = d.data ?? [];
            qualityPage.value = 1;
        } else if (tab === 'gelombang') {
            if (!force && gelombangList.value.length) { loading.value = false; return; }
            const r = await fetch(`/api/petugas/gelombang?group_by=${gelombangGroupBy.value}`, { headers: H });
            gelombangList.value = await r.json();
        } else if (tab === 'matrix') {
            if (!force && matrixData.value.length) { loading.value = false; return; }
            const r = await fetch(`/api/petugas/matrix?${geoParams()}`, { headers: H });
            const d = await r.json();
            matrixData.value = d.data ?? [];
            matrixPage.value = 1;
        } else if (tab === 'leaderboard') {
            if (!force && pencacahAll.value.length) { leaderboardPage.value = 1; loading.value = false; return; }
            if (!pencacahAll.value.length || force) {
                const r = await fetch(`/api/petugas/list?${geoParams()}`, { headers: H });
                const d = await r.json();
                pencacahAll.value = d.data ?? [];
            }
            leaderboardPage.value = 1;
        }
    } finally {
 loading.value = false; 
}
}

onMounted(() => fetchTab('pencacah'));
watch(activeTab, tab => fetchTab(tab));
watch([filterKec, filterDes, filterSls, filterSubsls], () => fetchTab(activeTab.value, true));
watch(gelombangGroupBy, () => {
 if (activeTab.value === 'gelombang') {
fetchTab('gelombang', true);
}
});
watch(turnaroundView, () => {
 turnaroundPage.value = 1; 
});

// ── charts ─────────────────────────────────────────────────────────────────
const chartColor = computed(() => (isDark.value ? '#9ca3af' : '#6b7280'));
function fmtMin(m: number) {
    if (m < 60) {
return `${Math.round(m)} mnt`;
}

    const h = Math.floor(m / 60), r = Math.round(m % 60);

    return r ? `${h}j ${r}m` : `${h}j`;
}

const turnaroundChart = computed(() => ({
    chart: { type: 'bar' as const, background: 'transparent', toolbar: { show: false }, animations: { enabled: false } },
    theme: { mode: (isDark.value ? 'dark' : 'light') as 'dark' | 'light' },
    plotOptions: { bar: { horizontal: true, borderRadius: 3 } },
    dataLabels: { enabled: true, formatter: (v: number) => fmtMin(v), style: { fontSize: '10px' } },
    xaxis: {
        categories: turnaroundData.value.slice(0, 20).map(r => r.nama ?? r.uid),
        labels: { style: { colors: chartColor.value, fontSize: '11px' } },
        title: { text: 'Rata-rata (menit)', style: { color: chartColor.value } },
    },
    yaxis: { labels: { style: { colors: chartColor.value, fontSize: '11px' }, maxWidth: 160 } },
    tooltip: { y: { formatter: (v: number) => fmtMin(v) } },
    colors: ['#6366f1'],
    grid: { borderColor: isDark.value ? '#374151' : '#e5e7eb' },
}));
const turnaroundSeries = computed(() => [{
    name: 'Rata-rata (menit)',
    data: turnaroundData.value.slice(0, 20).map(r => r.avg_minutes),
}]);

const matrixMedianMinutes = computed(() => {
    if (!matrixData.value.length) return 0;
    const sorted = [...matrixData.value].map(r => r.avg_minutes).sort((a, b) => a - b);
    const mid = Math.floor(sorted.length / 2);
    return sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
});
const matrixMedianError = computed(() => {
    if (!matrixData.value.length) return 0;
    const sorted = [...matrixData.value].map(r => r.rejection_rate).sort((a, b) => a - b);
    const mid = Math.floor(sorted.length / 2);
    return sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
});

const matrixChartOptions = computed(() => ({
    chart: { type: 'scatter' as const, background: 'transparent', toolbar: { show: false }, animations: { enabled: false }, zoom: { enabled: true } },
    theme: { mode: (isDark.value ? 'dark' : 'light') as 'dark' | 'light' },
    xaxis: {
        title: { text: 'Rata-rata waktu submit (menit) — lebih kecil lebih cepat', style: { color: isDark.value ? '#a1a1aa' : '#71717a', fontSize: '11px' } },
        labels: { formatter: (v: number) => fmtMin(v), style: { colors: isDark.value ? '#a1a1aa' : '#71717a' } },
    },
    yaxis: {
        title: { text: '% di-reject pengawas — lebih kecil lebih baik', style: { color: isDark.value ? '#a1a1aa' : '#71717a', fontSize: '11px' } },
        labels: { formatter: (v: number) => `${v}%`, style: { colors: isDark.value ? '#a1a1aa' : '#71717a' } },
    },
    annotations: {
        xaxis: [{ x: matrixMedianMinutes.value, borderColor: '#f97316', borderWidth: 2, strokeDashArray: 5, label: { text: 'Median', style: { color: '#f97316', background: 'transparent', fontWeight: 600 } } }],
        yaxis: [{ y: matrixMedianError.value, borderColor: '#6366f1', borderWidth: 2, strokeDashArray: 5, label: { text: 'Median', style: { color: '#6366f1', background: 'transparent', fontWeight: 600 } } }],
    },
    tooltip: {
        custom: ({ seriesIndex: _s, dataPointIndex: i }: { seriesIndex: number; dataPointIndex: number }) => {
            const r = matrixData.value[i];
            if (!r) return '';
            return `<div class="p-2 text-xs"><b>${r.nama}</b><br/>Waktu: ${fmtMin(r.avg_minutes)}<br/>Reject: ${r.rejection_rate}%<br/>Total: ${r.total}</div>`;
        },
    },
    colors: ['#6366f1'],
    markers: { size: 7, strokeWidth: 0 },
    grid: { borderColor: isDark.value ? '#374151' : '#e5e7eb' },
}));
const matrixSeries = computed(() => [{
    name: 'Pencacah',
    data: matrixData.value.map(r => [r.avg_minutes, r.rejection_rate]),
}]);

const gelombangChart = computed(() => ({
    chart: { type: 'bar' as const, background: 'transparent', toolbar: { show: false }, animations: { enabled: false } },
    theme: { mode: (isDark.value ? 'dark' : 'light') as 'dark' | 'light' },
    plotOptions: { bar: { borderRadius: 3, columnWidth: '60%' } },
    dataLabels: { enabled: false },
    xaxis: { categories: gelombangList.value.map(r => r.label), labels: { style: { colors: chartColor.value, fontSize: '11px' } } },
    yaxis: { max: 100, labels: { formatter: (v: number) => `${v}%`, style: { colors: chartColor.value } } },
    tooltip: { y: { formatter: (v: number) => `${v}%` } },
    colors: ['#10b981', '#6366f1'],
    grid: { borderColor: isDark.value ? '#374151' : '#e5e7eb' },
    legend: { labels: { colors: chartColor.value } },
}));
const gelombangSeries = computed(() => [
    { name: 'Progress %', data: gelombangList.value.map(r => r.progress_pct) },
    { name: 'Approved %', data: gelombangList.value.map(r => r.approved_pct) },
]);
</script>

<template>
    <Head title="Analitik Petugas" />

    <div v-if="!db_ready" class="flex h-64 items-center justify-center text-sm text-muted-foreground">
        Import database FASIH terlebih dahulu.
    </div>

    <div v-else class="space-y-3 p-4">
        <!-- Header -->
        <div>
            <h1 class="text-lg font-semibold">Analitik Petugas</h1>
            <p class="text-xs text-muted-foreground">Performa pencacah & pengawas berdasarkan data penugasan terkini</p>
        </div>

        <!-- Wilayah filter (shared) -->
        <div class="flex flex-wrap gap-2">
            <select v-model="filterKec"
                class="h-8 rounded-md border border-input bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-ring">
                <option value="">Semua Kecamatan</option>
                <option v-for="k in kecOptions" :key="k.code" :value="k.code">{{ k.name }}</option>
            </select>
            <select v-model="filterDes"
                class="h-8 rounded-md border border-input bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-ring">
                <option value="">Semua Desa</option>
                <option v-for="d in desOptions" :key="d.code" :value="d.code">{{ d.name }}</option>
            </select>
            <select v-if="filterDes" v-model="filterSls"
                class="h-8 rounded-md border border-input bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-ring">
                <option value="">Semua SLS</option>
                <option v-for="s in slsOptions" :key="s.code" :value="s.code">{{ s.name }}</option>
            </select>
            <select v-if="filterSls" v-model="filterSubsls"
                class="h-8 rounded-md border border-input bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-ring">
                <option value="">Semua Sub-SLS</option>
                <option v-for="ss in subslsOptions" :key="ss.code" :value="ss.code">{{ ss.name }}</option>
            </select>
        </div>

        <!-- Tabs -->
        <div class="flex flex-wrap gap-1 rounded-lg border bg-muted/40 p-1">
            <button v-for="tab in [
                    { id: 'pencacah', label: 'Pencacah' },
                    { id: 'turnaround', label: 'Kecepatan Proses' },
                    { id: 'quality', label: 'Quality Control' },
                    { id: 'gelombang', label: 'Gelombang / TC' },
                    { id: 'matrix', label: 'Matriks Kinerja' },
                    { id: 'leaderboard', label: 'Leaderboard' },
                ]" :key="tab.id"
                :class="['rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                    activeTab === tab.id ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground']"
                @click="activeTab = tab.id as typeof activeTab">
                {{ tab.label }}
            </button>
        </div>

        <div v-if="loading" class="flex h-48 items-center justify-center text-sm text-muted-foreground">Memuat data...</div>

        <!-- ── Tab: Pencacah ──────────────────────────────────────── -->
        <template v-else-if="activeTab === 'pencacah'">
            <div class="flex items-center justify-between">
                <span class="text-xs text-muted-foreground">{{ pencacahAll.length }} pencacah</span>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-muted-foreground">Per halaman:</span>
                    <select v-model="pencacahPer" class="h-7 rounded border border-input bg-background px-1 text-xs" @change="pencacahPage = 1">
                        <option :value="10">10</option><option :value="20">20</option><option :value="50">50</option>
                    </select>
                </div>
            </div>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-xs">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">#</th>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">Nama</th>
                            <th v-for="col in [{key:'total',label:'Total'},{key:'draft',label:'Draft'},{key:'submitted',label:'Submit'},{key:'approved',label:'Approved'},{key:'rejected',label:'Rejected'},{key:'rejection_rate',label:'Reject %'}]"
                                :key="col.key"
                                class="cursor-pointer px-3 py-2 text-right font-medium text-muted-foreground hover:text-foreground"
                                @click="setSort(col.key as keyof PetugasRow)">
                                {{ col.label }} {{ sortKey === col.key ? (sortDir === 'desc' ? '↓' : '↑') : '' }}
                            </th>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">Progress</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="(row, i) in paginatedPencacah" :key="row.uid" class="hover:bg-muted/30">
                            <td class="px-3 py-2 text-muted-foreground">{{ (pencacahPage - 1) * pencacahPer + i + 1 }}</td>
                            <td class="px-3 py-2"><div class="font-medium">{{ row.nama }}</div><div class="text-[10px] text-muted-foreground">{{ row.email }}</div></td>
                            <td class="px-3 py-2 text-right font-medium">{{ row.total.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right text-muted-foreground">{{ row.draft.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right text-blue-600 dark:text-blue-400">{{ row.submitted.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right text-emerald-600 dark:text-emerald-400">{{ row.approved.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right text-red-600 dark:text-red-400">{{ row.rejected.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right">
                                <span :class="['rounded px-1.5 py-0.5 text-xs font-medium',
                                    row.rejection_rate > 20 ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'
                                    : row.rejection_rate > 10 ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'
                                    : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300']">
                                    {{ row.rejection_rate }}%
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 w-20 overflow-hidden rounded-full bg-muted">
                                        <div class="h-full rounded-full bg-emerald-500" :style="{ width: `${Math.min(100, row.progress_pct)}%` }" />
                                    </div>
                                    <span class="text-muted-foreground">{{ row.progress_pct }}%</span>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!pencacahAll.length"><td colspan="9" class="py-8 text-center text-muted-foreground">Tidak ada data</td></tr>
                    </tbody>
                </table>
            </div>
            <div v-if="pencacahPages > 1" class="flex items-center justify-between text-xs text-muted-foreground">
                <span>{{ ((pencacahPage-1)*pencacahPer+1).toLocaleString('id') }}–{{ Math.min(pencacahPage*pencacahPer, pencacahAll.length).toLocaleString('id') }} dari {{ pencacahAll.length.toLocaleString('id') }}</span>
                <div class="flex gap-1">
                    <button :disabled="pencacahPage===1" class="rounded border px-2 py-1 disabled:opacity-40 hover:bg-muted/40" @click="pencacahPage--">‹</button>
                    <span class="px-2 py-1">{{ pencacahPage }}/{{ pencacahPages }}</span>
                    <button :disabled="pencacahPage>=pencacahPages" class="rounded border px-2 py-1 disabled:opacity-40 hover:bg-muted/40" @click="pencacahPage++">›</button>
                </div>
            </div>
        </template>

        <!-- ── Tab: Turnaround ────────────────────────────────────── -->
        <template v-else-if="activeTab === 'turnaround'" >
            <div class="flex flex-wrap gap-2">
                <button v-for="v in [{id:'pencacah',label:'Pencacah — DRAFT→Submit'},{id:'pengawas',label:'Pengawas — Submit→Review'}]"
                    :key="v.id"
                    :class="['rounded-md border px-3 py-1.5 text-xs font-medium transition-colors',
                        turnaroundView === v.id ? 'border-primary bg-primary text-primary-foreground' : 'border-input bg-background text-muted-foreground hover:text-foreground']"
                    @click="turnaroundView = v.id as typeof turnaroundView">
                    {{ v.label }}
                </button>
            </div>
            <div v-if="turnaroundData.length" class="rounded-lg border p-3">
                <p class="mb-2 text-xs text-muted-foreground">Menampilkan 20 tercepat di chart. Tabel menampilkan semua.</p>
                <VueApexCharts type="bar"
                    :height="Math.max(200, turnaroundData.slice(0,20).length * 28)"
                    :options="turnaroundChart" :series="turnaroundSeries" />
            </div>
            <div class="flex items-center justify-between">
                <span class="text-xs text-muted-foreground">{{ turnaroundData.length }} entri</span>
                <select v-model="turnaroundPer" class="h-7 rounded border border-input bg-background px-1 text-xs" @change="turnaroundPage = 1">
                    <option :value="10">10</option><option :value="20">20</option><option :value="50">50</option>
                </select>
            </div>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-xs">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">Nama</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Rata-rata</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Sampel</th>
                            <template v-if="turnaroundView === 'pengawas'">
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Approved</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Rejected</th>
                            </template>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-if="turnaroundData.length === 0"><td colspan="5" class="py-8 text-center text-muted-foreground">Tidak ada data (pencacah perlu ≥ 3 submit)</td></tr>
                        <template v-else-if="turnaroundView === 'pencacah'">
                            <tr v-for="r in paginatedTurnaround as TurnaroundPencacah[]" :key="r.uid" class="hover:bg-muted/30">
                                <td class="px-3 py-2">{{ r.nama ?? r.uid }}</td>
                                <td class="px-3 py-2 text-right font-medium">{{ fmtMin(r.avg_minutes) }}</td>
                                <td class="px-3 py-2 text-right text-muted-foreground">{{ r.sample_count }}</td>
                            </tr>
                        </template>
                        <template v-else>
                            <tr v-for="r in paginatedTurnaround as TurnaroundPengawas[]" :key="r.uid" class="hover:bg-muted/30">
                                <td class="px-3 py-2">{{ r.nama ?? r.uid }}</td>
                                <td class="px-3 py-2 text-right font-medium">{{ fmtMin(r.avg_minutes) }}</td>
                                <td class="px-3 py-2 text-right text-muted-foreground">{{ r.sample_count }}</td>
                                <td class="px-3 py-2 text-right text-emerald-600 dark:text-emerald-400">{{ r.approved_count }}</td>
                                <td class="px-3 py-2 text-right text-red-600 dark:text-red-400">{{ r.rejected_count }}</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div v-if="turnaroundPages > 1" class="flex items-center justify-between text-xs text-muted-foreground">
                <span>{{ ((turnaroundPage-1)*turnaroundPer+1) }}–{{ Math.min(turnaroundPage*turnaroundPer,turnaroundData.length) }} dari {{ turnaroundData.length }}</span>
                <div class="flex gap-1">
                    <button :disabled="turnaroundPage===1" class="rounded border px-2 py-1 disabled:opacity-40 hover:bg-muted/40" @click="turnaroundPage--">‹</button>
                    <span class="px-2 py-1">{{ turnaroundPage }}/{{ turnaroundPages }}</span>
                    <button :disabled="turnaroundPage>=turnaroundPages" class="rounded border px-2 py-1 disabled:opacity-40 hover:bg-muted/40" @click="turnaroundPage++">›</button>
                </div>
            </div>
        </template>

        <!-- ── Tab: Quality ───────────────────────────────────────── -->
        <template v-else-if="activeTab === 'quality'">
            <div class="flex items-center justify-between">
                <p class="text-xs text-muted-foreground">Berdasarkan <code class="rounded bg-muted px-1">sum_error</code>, <code class="rounded bg-muted px-1">sum_clean</code>, <code class="rounded bg-muted px-1">sum_remark</code>.</p>
                <select v-model="qualityPer" class="h-7 rounded border border-input bg-background px-1 text-xs" @change="qualityPage = 1">
                    <option :value="10">10</option><option :value="20">20</option><option :value="50">50</option>
                </select>
            </div>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-xs">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">#</th>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">Nama</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Total</th>
                            <th class="cursor-pointer px-3 py-2 text-right font-medium text-muted-foreground hover:text-foreground" @click="qualitySortKey='avg_error'; qualityPage=1">Avg Error {{ qualitySortKey==='avg_error'?'↓':'' }}</th>
                            <th class="cursor-pointer px-3 py-2 text-right font-medium text-muted-foreground hover:text-foreground" @click="qualitySortKey='avg_clean'; qualityPage=1">Avg Clean {{ qualitySortKey==='avg_clean'?'↓':'' }}</th>
                            <th class="cursor-pointer px-3 py-2 text-right font-medium text-muted-foreground hover:text-foreground" @click="qualitySortKey='error_pct'; qualityPage=1">% Bermasalah {{ qualitySortKey==='error_pct'?'↓':'' }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="(row, i) in paginatedQuality" :key="row.uid" class="hover:bg-muted/30">
                            <td class="px-3 py-2 text-muted-foreground">{{ (qualityPage-1)*qualityPer+i+1 }}</td>
                            <td class="px-3 py-2"><div class="font-medium">{{ row.nama }}</div><div class="text-[10px] text-muted-foreground">{{ row.email }}</div></td>
                            <td class="px-3 py-2 text-right">{{ row.total.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right"><span :class="row.avg_error > 1 ? 'font-medium text-red-600 dark:text-red-400' : ''">{{ row.avg_error.toFixed(2) }}</span></td>
                            <td class="px-3 py-2 text-right text-emerald-600 dark:text-emerald-400">{{ row.avg_clean.toFixed(2) }}</td>
                            <td class="px-3 py-2 text-right">
                                <span :class="['rounded px-1.5 py-0.5 font-medium',
                                    row.error_pct > 30 ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'
                                    : row.error_pct > 10 ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'
                                    : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300']">
                                    {{ row.error_pct }}%
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!qualityAll.length"><td colspan="6" class="py-8 text-center text-muted-foreground">Tidak ada data</td></tr>
                    </tbody>
                </table>
            </div>
            <div v-if="qualityPages > 1" class="flex items-center justify-between text-xs text-muted-foreground">
                <span>{{ ((qualityPage-1)*qualityPer+1) }}–{{ Math.min(qualityPage*qualityPer,qualityAll.length) }} dari {{ qualityAll.length }}</span>
                <div class="flex gap-1">
                    <button :disabled="qualityPage===1" class="rounded border px-2 py-1 disabled:opacity-40 hover:bg-muted/40" @click="qualityPage--">‹</button>
                    <span class="px-2 py-1">{{ qualityPage }}/{{ qualityPages }}</span>
                    <button :disabled="qualityPage>=qualityPages" class="rounded border px-2 py-1 disabled:opacity-40 hover:bg-muted/40" @click="qualityPage++">›</button>
                </div>
            </div>
        </template>

        <!-- ── Tab: Gelombang / TC ────────────────────────────────── -->
        <template v-else-if="activeTab === 'gelombang'">
            <div class="flex flex-wrap gap-2">
                <button v-for="g in [{id:'gelombang',label:'Gelombang'},{id:'kelas',label:'Kelas'},{id:'tc',label:'Training Center'}]" :key="g.id"
                    :class="['rounded-md border px-3 py-1.5 text-xs font-medium transition-colors',
                        gelombangGroupBy === g.id ? 'border-primary bg-primary text-primary-foreground' : 'border-input bg-background text-muted-foreground hover:text-foreground']"
                    @click="gelombangGroupBy = g.id as typeof gelombangGroupBy">
                    {{ g.label }}
                </button>
            </div>
            <div v-if="gelombangList.length" class="rounded-lg border p-3">
                <VueApexCharts type="bar" :height="260" :options="gelombangChart" :series="gelombangSeries" />
            </div>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-xs">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">{{ gelombangGroupBy === 'gelombang' ? 'Gelombang' : gelombangGroupBy === 'kelas' ? 'Kelas' : 'Training Center' }}</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Pencacah</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Total Assignment</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Submitted</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Approved</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Rejected</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Progress</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in gelombangList" :key="row.label" class="hover:bg-muted/30">
                            <td class="px-3 py-2 font-medium">{{ row.label }}</td>
                            <td class="px-3 py-2 text-right">{{ row.total_pencacah }}</td>
                            <td class="px-3 py-2 text-right">{{ row.total_assignment.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right text-blue-600 dark:text-blue-400">{{ row.submitted.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right text-emerald-600 dark:text-emerald-400">{{ row.approved.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right text-red-600 dark:text-red-400">{{ row.rejected.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right font-medium">{{ row.progress_pct }}%</td>
                        </tr>
                        <tr v-if="!gelombangList.length"><td colspan="7" class="py-8 text-center text-muted-foreground">Tidak ada data</td></tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── Tab: Matriks Kinerja ──────────────────────────────── -->
        <template v-else-if="activeTab === 'matrix'">
            <p class="text-xs text-muted-foreground">Scatter plot kecepatan vs akurasi per pencacah (min 3 submit). Titik kiri-bawah = ideal.</p>
            <div v-if="!matrixData.length" class="flex h-48 items-center justify-center text-sm text-muted-foreground">
                Tidak ada data (pencacah perlu ≥ 3 submit).
            </div>
            <div v-else class="rounded-lg border p-3">
                <div class="mb-3 grid grid-cols-2 gap-2 text-xs">
                    <div class="rounded bg-emerald-50 px-2 py-1 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400">Kiri-bawah: Cepat + Akurat (top performer)</div>
                    <div class="rounded bg-amber-50 px-2 py-1 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400">Kanan-bawah: Cepat tapi banyak error (perlu pembinaan)</div>
                    <div class="rounded bg-blue-50 px-2 py-1 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400">Kiri-atas: Akurat tapi lambat (perlu motivasi)</div>
                    <div class="rounded bg-red-50 px-2 py-1 text-red-700 dark:bg-red-900/20 dark:text-red-400">Kanan-atas: Lambat + banyak error (perlu bimbingan)</div>
                </div>
                <VueApexCharts type="scatter" :height="400" :options="matrixChartOptions" :series="matrixSeries" />
            </div>
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-muted-foreground">{{ matrixData.length.toLocaleString('id') }} pencacah</span>
                    <select v-model="matrixPer" class="h-7 rounded border border-input bg-background px-1 text-xs" @change="matrixPage = 1">
                        <option :value="10">10/hal</option>
                        <option :value="20">20/hal</option>
                        <option :value="50">50/hal</option>
                    </select>
                </div>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-xs">
                        <thead class="bg-muted/50">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-muted-foreground">Nama</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Waktu Avg</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">% Reject</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Total</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Sampel</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="r in paginatedMatrix" :key="r.uid" class="hover:bg-muted/30">
                                <td class="px-3 py-2 font-medium">{{ r.nama }}</td>
                                <td class="px-3 py-2 text-right">{{ fmtMin(r.avg_minutes) }}</td>
                                <td class="px-3 py-2 text-right">
                                    <span :class="['rounded px-1.5 py-0.5 font-medium', r.rejection_rate > 30 ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' : r.rejection_rate > 10 ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300']">{{ r.rejection_rate }}%</span>
                                </td>
                                <td class="px-3 py-2 text-right">{{ r.total }}</td>
                                <td class="px-3 py-2 text-right text-muted-foreground">{{ r.sample_count }}</td>
                            </tr>
                            <tr v-if="!matrixData.length"><td colspan="5" class="py-8 text-center text-muted-foreground">Tidak ada data</td></tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="matrixPages > 1" class="flex items-center justify-between text-xs text-muted-foreground">
                    <span>{{ ((matrixPage-1)*matrixPer+1).toLocaleString('id') }}–{{ Math.min(matrixPage*matrixPer, matrixData.length).toLocaleString('id') }} dari {{ matrixData.length.toLocaleString('id') }}</span>
                    <div class="flex items-center gap-1">
                        <button :disabled="matrixPage===1" class="rounded border px-2 py-1 disabled:opacity-40 hover:bg-muted/40" @click="matrixPage--">‹</button>
                        <span class="px-2 py-1">{{ matrixPage }}/{{ matrixPages }}</span>
                        <button :disabled="matrixPage>=matrixPages" class="rounded border px-2 py-1 disabled:opacity-40 hover:bg-muted/40" @click="matrixPage++">›</button>
                    </div>
                </div>
            </div>
        </template>

        <!-- ── Tab: Leaderboard ───────────────────────────────────── -->
        <template v-else-if="activeTab === 'leaderboard'">
            <p class="text-xs text-muted-foreground">Skor = Progress% × 50% + (100 − Reject%) × 30% + Approved% × 20%</p>
            <div v-if="!leaderboardData.length" class="flex h-48 items-center justify-center text-sm text-muted-foreground">
                Tidak ada data. Muat tab Pencacah terlebih dahulu.
            </div>
            <div v-else class="space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-muted-foreground">{{ leaderboardData.length.toLocaleString('id') }} petugas</span>
                    <select v-model="leaderboardPer" class="h-7 rounded border border-input bg-background px-1 text-xs" @change="leaderboardPage = 1">
                        <option :value="10">10/hal</option>
                        <option :value="20">20/hal</option>
                        <option :value="50">50/hal</option>
                    </select>
                </div>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-xs">
                        <thead class="bg-muted/50">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-muted-foreground">Rank</th>
                                <th class="px-3 py-2 text-left font-medium text-muted-foreground">Nama</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Skor</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Progress</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Reject %</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Total</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Approved</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="r in paginatedLeaderboard" :key="r.uid" class="hover:bg-muted/30">
                                <td class="px-3 py-2 font-bold">
                                    <span v-if="r.rank === 1" class="text-yellow-500">🥇 1</span>
                                    <span v-else-if="r.rank === 2" class="text-zinc-400">🥈 2</span>
                                    <span v-else-if="r.rank === 3" class="text-amber-600">🥉 3</span>
                                    <span v-else class="text-muted-foreground">{{ r.rank }}</span>
                                </td>
                                <td class="px-3 py-2"><div class="font-medium">{{ r.nama }}</div><div class="text-[10px] text-muted-foreground">{{ r.email }}</div></td>
                                <td class="px-3 py-2 text-right">
                                    <span :class="['rounded px-1.5 py-0.5 font-bold', r.score >= 80 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : r.score >= 60 ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300']">{{ r.score }}</span>
                                </td>
                                <td class="px-3 py-2 text-right">{{ r.progress_pct }}%</td>
                                <td class="px-3 py-2 text-right"><span :class="r.rejection_rate > 20 ? 'text-red-600 dark:text-red-400' : ''">{{ r.rejection_rate }}%</span></td>
                                <td class="px-3 py-2 text-right">{{ r.total.toLocaleString('id') }}</td>
                                <td class="px-3 py-2 text-right text-emerald-600 dark:text-emerald-400">{{ r.approved.toLocaleString('id') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="leaderboardPages > 1" class="flex items-center justify-between text-xs text-muted-foreground">
                    <span>{{ ((leaderboardPage-1)*leaderboardPer+1).toLocaleString('id') }}–{{ Math.min(leaderboardPage*leaderboardPer, leaderboardData.length).toLocaleString('id') }} dari {{ leaderboardData.length.toLocaleString('id') }}</span>
                    <div class="flex items-center gap-1">
                        <button :disabled="leaderboardPage===1" class="rounded border px-2 py-1 disabled:opacity-40 hover:bg-muted/40" @click="leaderboardPage--">‹</button>
                        <span class="px-2 py-1">{{ leaderboardPage }}/{{ leaderboardPages }}</span>
                        <button :disabled="leaderboardPage>=leaderboardPages" class="rounded border px-2 py-1 disabled:opacity-40 hover:bg-muted/40" @click="leaderboardPage++">›</button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
