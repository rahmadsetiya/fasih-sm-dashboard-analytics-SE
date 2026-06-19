<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, reactive, watch, computed, onMounted } from 'vue';
import { useDark } from '@vueuse/core';
import VueApexCharts from 'vue3-apexcharts';

const props = defineProps<{
    snapshots: string[];
    db_ready: boolean;
}>();

// ── types ─────────────────────────────────────────────────────────────────
type Role  = 'pengawas' | 'pencacah';
type Level = 'kec' | 'desa' | 'sls' | 'by_pengawas' | 'by_pencacah';
type FilterLevel = '' | 'kec' | 'desa' | 'sls';

interface Metrics {
    total_petugas: number;
    total_kec: number;
    total_desa: number;
    total_rt: number;
    progress_pct: number;
    approved_pct: number;
    submitted_pct: number;
}
interface TrendPoint {
    snapshot_at: string;
    progress_pct: number;
    submitted_pct: number;
    approved_pct: number;
    total: number;
}
interface BreakdownRow {
    key: string;
    label: string;
    total: number;
    progress_pct: number;
    approved_pct: number;
    statuses: Record<string, number>;
    nmkec?: string;
    nmdesa?: string;
    kec_count?: number;
    desa_count?: number;
}
interface FilterOption {
    code: string;
    label: string;
    total: number;
    kec?: string;
}

// ── state ─────────────────────────────────────────────────────────────────
const snapshots = ref<string[]>(props.snapshots);
const loading   = ref(false);

const filters = reactive({
    snapshot:     props.snapshots[0] ?? '',
    role:         'pengawas' as Role,
    level:        'kec' as Level,
    filter_codes: [] as string[],
    filter_level: '' as FilterLevel,
});

// When Inertia reloads props (e.g. after DB import), sync snapshots and auto-fetch
watch(() => props.snapshots, (val) => {
    snapshots.value = val;
    if (!filters.snapshot && val.length) {
        filters.snapshot = val[0];
    }
}, { deep: true });

const metrics      = ref<Metrics>({ total_petugas:0, total_kec:0, total_desa:0, total_rt:0, progress_pct:0, approved_pct:0, submitted_pct:0 });
const statusTotals = ref<Record<string,number>>({});
const breakdown    = ref<BreakdownRow[]>([]);
const trend        = ref<TrendPoint[]>([]);
const filterOptions = ref<FilterOption[] | null>(null);
const filterSearch  = ref('');

// ── pagination ────────────────────────────────────────────────────────────
const pageSize    = ref<10|20|50>(20);
const currentPage = ref(1);

// ── fetch ─────────────────────────────────────────────────────────────────
async function fetchData() {
    if (!filters.snapshot) return;
    loading.value = true;
    try {
        const params = new URLSearchParams({
            snapshot:     filters.snapshot,
            role:         filters.role,
            level:        filters.level,
            filter_level: filters.filter_level,
        });
        filters.filter_codes.forEach(c => params.append('filter_codes[]', c));

        const res  = await fetch(`/api/data?${params}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json();
        metrics.value       = data.metrics;
        statusTotals.value  = data.status_totals;
        breakdown.value     = data.breakdown;
        trend.value         = data.trend;
        filterOptions.value = data.filter_options;
        currentPage.value   = 1;
    } finally {
        loading.value = false;
    }
}

watch(filters, fetchData, { deep: true });
onMounted(fetchData);

// ── level switch ──────────────────────────────────────────────────────────
const LEVEL_FILTER_MAP: Record<Level, FilterLevel> = {
    kec:          '',
    desa:         'kec',
    sls:          'desa',
    by_pengawas:  '',
    by_pencacah:  '',
};

function setLevel(l: Level) {
    filters.level        = l;
    filters.filter_codes = [];
    filters.filter_level = LEVEL_FILTER_MAP[l];
    filterSearch.value   = '';
}

function toggleFilterCode(code: string) {
    const idx = filters.filter_codes.indexOf(code);
    if (idx === -1) filters.filter_codes.push(code);
    else            filters.filter_codes.splice(idx, 1);
}

function selectAllFilter() {
    filters.filter_codes = filteredOptions.value.map(o => o.code);
}

function clearFilter() {
    filters.filter_codes = [];
}

// ── filter option search ──────────────────────────────────────────────────
const filteredOptions = computed(() => {
    if (!filterOptions.value) return [];
    const q = filterSearch.value.toLowerCase();
    return q
        ? filterOptions.value.filter(o => o.label.toLowerCase().includes(q) || o.code.includes(q) || (o.kec ?? '').toLowerCase().includes(q))
        : filterOptions.value;
});

// grouped desa for SLS filter
const groupedDesaOptions = computed(() => {
    if (filters.level !== 'sls' || !filterOptions.value) return null;
    const groups: Record<string, FilterOption[]> = {};
    for (const opt of filteredOptions.value) {
        const kec = opt.kec ?? '—';
        if (!groups[kec]) groups[kec] = [];
        groups[kec].push(opt);
    }
    return groups;
});

// ── sort ──────────────────────────────────────────────────────────────────
type SortKey = keyof Pick<BreakdownRow, 'label'|'total'|'progress_pct'|'approved_pct'> | keyof BreakdownRow['statuses'];
const sortCol = ref<string>('total');
const sortDir = ref<'asc'|'desc'>('desc');

function toggleSort(col: string) {
    if (sortCol.value === col) sortDir.value = sortDir.value === 'desc' ? 'asc' : 'desc';
    else { sortCol.value = col; sortDir.value = 'desc'; }
}

const sortedBreakdown = computed(() => {
    const copy = [...breakdown.value];
    copy.sort((a, b) => {
        const va = (STATUS_COLS.includes(sortCol.value) ? (a.statuses[sortCol.value] ?? 0) : (a as any)[sortCol.value]) as number;
        const vb = (STATUS_COLS.includes(sortCol.value) ? (b.statuses[sortCol.value] ?? 0) : (b as any)[sortCol.value]) as number;
        if (typeof va === 'string') return sortDir.value === 'desc' ? (vb as any).localeCompare(va) : (va as any).localeCompare(vb as any);
        return sortDir.value === 'desc' ? (vb as number) - (va as number) : (va as number) - (vb as number);
    });
    return copy;
});

// ── pagination computed ───────────────────────────────────────────────────
const totalRows      = computed(() => sortedBreakdown.value.length);
const totalPages     = computed(() => Math.max(1, Math.ceil(totalRows.value / pageSize.value)));
const paginatedRows  = computed(() => {
    const s = (currentPage.value - 1) * pageSize.value;
    return sortedBreakdown.value.slice(s, s + pageSize.value);
});
const pageStart = computed(() => Math.min((currentPage.value - 1) * pageSize.value + 1, totalRows.value));
const pageEnd   = computed(() => Math.min(currentPage.value * pageSize.value, totalRows.value));

function goPage(p: number) { currentPage.value = Math.max(1, Math.min(p, totalPages.value)); }

watch(pageSize, () => { currentPage.value = 1; });

// ── chart data ────────────────────────────────────────────────────────────
const STATUS_COLS = [
    'OPEN', 'DRAFT', 'SUBMITTED BY Pencacah',
    'APPROVED BY Pengawas', 'REJECTED BY Pengawas',
    'EDITED BY Pengawas', 'REVOKED BY Pengawas', 'SUBMITTED RESPONDENT',
];

const STATUS_META: Record<string, { short: string; color: string; title: string }> = {
    'OPEN':                   { short: 'Open',     color: '#64748b', title: 'Belum diisi' },
    'DRAFT':                  { short: 'Draft',    color: '#2563eb', title: 'Sedang diisi' },
    'SUBMITTED BY Pencacah':  { short: 'Sub.P',   color: '#7c3aed', title: 'Diserahkan Pencacah' },
    'APPROVED BY Pengawas':   { short: 'App.P',   color: '#059669', title: 'Disetujui Pengawas' },
    'REJECTED BY Pengawas':   { short: 'Rej.P',   color: '#dc2626', title: 'Ditolak Pengawas' },
    'EDITED BY Pengawas':     { short: 'Edit.P',  color: '#d97706', title: 'Diedit Pengawas' },
    'REVOKED BY Pengawas':    { short: 'Rev.P',   color: '#be185d', title: 'Dicabut Pengawas' },
    'SUBMITTED RESPONDENT':   { short: 'Sub.R',   color: '#4f46e5', title: 'Submit Responden' },
};

const isDark    = useDark();
const chartBg   = computed(() => isDark.value ? '#18181b' : '#ffffff');
const chartMode = computed(() => isDark.value ? 'dark' as const : 'light' as const);

const donutSeries = computed(() => STATUS_COLS.map(c => statusTotals.value[c] ?? 0));
const donutColors = STATUS_COLS.map(c => STATUS_META[c].color);
const donutLabels = STATUS_COLS.map(c => STATUS_META[c].title);

const donutOptions = computed(() => ({
    chart: { type: 'donut' as const, background: chartBg.value, toolbar: { show: false } },
    theme: { mode: chartMode.value },
    labels: donutLabels,
    colors: donutColors,
    legend: { position: 'bottom' as const, fontSize: '11px' },
    dataLabels: { enabled: false },
    plotOptions: { pie: { donut: { size: '62%', labels: {
        show: true,
        total: { show: true, label: 'Total RT', fontSize: '12px', formatter: () => metrics.value.total_rt.toLocaleString('id-ID') },
    } } } },
    stroke: { width: 0 },
    tooltip: { y: { formatter: (v: number) => v.toLocaleString('id-ID') } },
}));

const TOP_N = 15;
const topBreakdown   = computed(() => sortedBreakdown.value.slice(0, TOP_N));
const barCategories  = computed(() => topBreakdown.value.map(r => r.label.slice(0, 22)));
const barSeries = computed(() => [
    { name: 'Progress %',  data: topBreakdown.value.map(r => r.progress_pct)  },
    { name: 'Approved %',  data: topBreakdown.value.map(r => r.approved_pct)  },
]);

const barOptions = computed(() => ({
    chart: { type: 'bar' as const, background: chartBg.value, toolbar: { show: false } },
    theme: { mode: chartMode.value },
    plotOptions: { bar: { horizontal: true, barHeight: '55%', borderRadius: 3 } },
    colors: ['#059669', '#1d4ed8'],
    xaxis: { categories: barCategories.value, max: 100, labels: { formatter: (v: number) => v + '%', style: { fontSize: '11px' } } },
    yaxis: { labels: { style: { fontSize: '11px' } } },
    tooltip: { y: { formatter: (v: number) => v.toFixed(1) + '%' } },
    dataLabels: { enabled: false },
    legend: { position: 'top' as const, fontSize: '12px' },
}));

const trendSeries = computed(() => [
    { name: 'Progress %',  data: trend.value.map(t => t.progress_pct)  },
    { name: 'Submitted %', data: trend.value.map(t => t.submitted_pct) },
    { name: 'Approved %',  data: trend.value.map(t => t.approved_pct)  },
]);
const trendCategories = computed(() =>
    trend.value.map(t => {
        const d = new Date(t.snapshot_at);
        return `${d.getDate().toString().padStart(2,'0')}/${(d.getMonth()+1).toString().padStart(2,'0')} ${d.getHours().toString().padStart(2,'0')}:${d.getMinutes().toString().padStart(2,'0')}`;
    })
);
const trendOptions = computed(() => ({
    chart: { type: 'line' as const, background: chartBg.value, toolbar: { show: false }, zoom: { enabled: false } },
    theme: { mode: chartMode.value },
    stroke: { curve: 'smooth' as const, width: 2.5 },
    colors: ['#059669', '#7c3aed', '#1d4ed8'],
    xaxis: { categories: trendCategories.value, labels: { rotate: -30, style: { fontSize: '10px' } } },
    yaxis: { max: 100, labels: { formatter: (v: number) => v + '%', style: { fontSize: '11px' } } },
    markers: { size: 5 },
    tooltip: { y: { formatter: (v: number) => v.toFixed(1) + '%' } },
    legend: { position: 'top' as const, fontSize: '12px' },
}));

// ── helpers ───────────────────────────────────────────────────────────────
const LEVEL_LABELS: Record<Level, string> = {
    kec: 'Kecamatan', desa: 'Desa', sls: 'SLS',
    by_pengawas: 'Per Pengawas', by_pencacah: 'Per Pencacah',
};

const FILTER_LEVEL_LABELS: Record<FilterLevel, string> = {
    '': '', kec: 'Kecamatan', desa: 'Desa', sls: 'SLS',
};

function fmtSnap(s: string) {
    return new Date(s).toLocaleString('id-ID', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' });
}

function pct(v: number) { return Math.min(100, Math.max(0, v)); }

const sortIcon = (col: string) =>
    sortCol.value !== col ? '' : (sortDir.value === 'desc' ? ' ↓' : ' ↑');

function rowContext(row: BreakdownRow): string {
    if (filters.level === 'desa' && row.nmkec) return row.nmkec;
    if (filters.level === 'sls'  && row.nmdesa) return `${row.nmkec ?? ''} / ${row.nmdesa}`;
    if ((filters.level === 'by_pengawas' || filters.level === 'by_pencacah') && row.desa_count !== undefined)
        return `${row.kec_count} Kec, ${row.desa_count} Desa`;
    return '';
}
</script>

<template>
    <Head title="Dashboard FASIH" />

    <!-- ── empty state ─────────────────────────────────────────────────── -->
    <div v-if="!db_ready" class="flex h-full flex-1 flex-col items-center justify-center gap-4 p-8 text-center">
        <div class="rounded-full bg-muted p-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="size-12 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 5.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
            </svg>
        </div>
        <div>
            <h2 class="text-lg font-semibold">Database belum diimport</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Klik <strong>Import Database</strong> di sidebar kiri bawah,<br>
                lalu upload file <code class="rounded bg-muted px-1 text-xs">fasih.db</code>.
            </p>
        </div>
    </div>

    <!-- ── dashboard ──────────────────────────────────────────────────── -->
    <div v-else class="flex h-full flex-1 flex-col gap-3 overflow-x-auto p-4">

        <!-- Filter bar -->
        <div class="flex flex-wrap items-center gap-2 rounded-xl border border-sidebar-border/70 bg-card px-3 py-2 dark:border-sidebar-border">
            <!-- Snapshot -->
            <div class="flex items-center gap-1.5">
                <span class="text-xs font-medium text-muted-foreground">Snapshot</span>
                <select v-model="filters.snapshot"
                    class="h-7 rounded-md border border-input bg-background px-2 text-xs text-foreground focus:outline-none focus:ring-2 focus:ring-ring">
                    <option v-for="s in snapshots" :key="s" :value="s">{{ fmtSnap(s) }}</option>
                </select>
            </div>

            <div class="h-4 w-px bg-border" />

            <!-- Role toggle -->
            <div class="flex overflow-hidden rounded-md border border-input text-xs">
                <button
                    :class="['px-3 py-1 font-medium transition-colors', filters.role==='pengawas' ? 'bg-primary text-primary-foreground' : 'bg-background text-foreground hover:bg-muted']"
                    @click="filters.role='pengawas'">Pengawas</button>
                <button
                    :class="['px-3 py-1 font-medium transition-colors', filters.role==='pencacah' ? 'bg-primary text-primary-foreground' : 'bg-background text-foreground hover:bg-muted']"
                    @click="filters.role='pencacah'">Pencacah</button>
            </div>

            <div class="h-4 w-px bg-border" />

            <!-- Level tabs -->
            <div class="flex flex-wrap gap-1">
                <button v-for="(lbl, key) in LEVEL_LABELS" :key="key"
                    :class="['rounded-full px-3 py-0.5 text-xs font-medium transition-colors',
                        filters.level === key ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/80']"
                    @click="setLevel(key as Level)">{{ lbl }}</button>
            </div>

            <!-- Active filter badge -->
            <div v-if="filters.filter_codes.length" class="ml-auto flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary">
                {{ filters.filter_codes.length }} {{ FILTER_LEVEL_LABELS[filters.filter_level] }} dipilih
                <button class="ml-1 rounded-full hover:text-destructive focus:outline-none" @click="clearFilter" aria-label="Hapus filter">✕</button>
            </div>

            <div v-if="loading" class="ml-auto animate-pulse text-xs text-muted-foreground">Memuat…</div>
        </div>

        <!-- Region filter panel -->
        <div v-if="filterOptions !== null" class="rounded-xl border border-sidebar-border/70 bg-card px-4 py-3 dark:border-sidebar-border">
            <div class="mb-2 flex items-center justify-between gap-3">
                <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                    Filter {{ FILTER_LEVEL_LABELS[filters.filter_level] }}
                </p>
                <div class="flex items-center gap-2">
                    <input v-model="filterSearch" type="search" placeholder="Cari…"
                        class="h-6 rounded-md border border-input bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-ring"
                        aria-label="Cari wilayah" />
                    <button class="text-xs text-primary underline-offset-2 hover:underline focus:outline-none" @click="selectAllFilter">Semua</button>
                    <button class="text-xs text-muted-foreground underline-offset-2 hover:underline focus:outline-none" @click="clearFilter">Hapus</button>
                </div>
            </div>

            <!-- Kecamatan filter (flat grid) -->
            <div v-if="filters.level === 'desa'" class="grid grid-cols-2 gap-1.5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                <label v-for="opt in filteredOptions" :key="opt.code"
                    :class="['flex cursor-pointer items-center gap-2 rounded-lg border px-2.5 py-1.5 text-xs transition-colors',
                        filters.filter_codes.includes(opt.code)
                            ? 'border-primary bg-primary/10 text-primary font-medium'
                            : 'border-border bg-background text-foreground hover:border-primary/50']">
                    <input type="checkbox" class="sr-only"
                        :checked="filters.filter_codes.includes(opt.code)"
                        @change="toggleFilterCode(opt.code)" :aria-label="opt.label" />
                    <span class="flex-1 truncate">{{ opt.label }}</span>
                    <span class="shrink-0 text-muted-foreground tabular-nums">{{ opt.total.toLocaleString('id-ID') }}</span>
                </label>
            </div>

            <!-- Desa filter (grouped by kecamatan) -->
            <div v-else-if="filters.level === 'sls' && groupedDesaOptions" class="max-h-52 overflow-y-auto space-y-3 pr-1">
                <div v-for="(items, kecName) in groupedDesaOptions" :key="kecName">
                    <p class="mb-1 text-xs font-semibold text-muted-foreground">{{ kecName }}</p>
                    <div class="grid grid-cols-2 gap-1 sm:grid-cols-3 md:grid-cols-4">
                        <label v-for="opt in items" :key="opt.code"
                            :class="['flex cursor-pointer items-center gap-1.5 rounded-md border px-2 py-1 text-xs transition-colors',
                                filters.filter_codes.includes(opt.code)
                                    ? 'border-primary bg-primary/10 text-primary font-medium'
                                    : 'border-border bg-background text-foreground hover:border-primary/50']">
                            <input type="checkbox" class="sr-only"
                                :checked="filters.filter_codes.includes(opt.code)"
                                @change="toggleFilterCode(opt.code)" :aria-label="opt.label" />
                            <span class="flex-1 truncate">{{ opt.label }}</span>
                            <span class="shrink-0 text-muted-foreground tabular-nums">{{ opt.total.toLocaleString('id-ID') }}</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Metric cards -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
            <div v-for="card in [
                { label: 'Petugas',     value: metrics.total_petugas,  fmt: 'n', color: 'text-violet-600 dark:text-violet-400'  },
                { label: 'Kecamatan',   value: metrics.total_kec,      fmt: 'n', color: 'text-blue-600 dark:text-blue-400'      },
                { label: 'Desa',        value: metrics.total_desa,     fmt: 'n', color: 'text-cyan-600 dark:text-cyan-400'      },
                { label: 'Total RT',    value: metrics.total_rt,       fmt: 'n', color: 'text-foreground'                        },
                { label: 'Progress',    value: metrics.progress_pct,   fmt: 'p', color: 'text-emerald-600 dark:text-emerald-400'},
                { label: 'Submitted',   value: metrics.submitted_pct,  fmt: 'p', color: 'text-violet-600 dark:text-violet-400'  },
                { label: 'Approved',    value: metrics.approved_pct,   fmt: 'p', color: 'text-blue-600 dark:text-blue-400'      },
            ]" :key="card.label"
                class="rounded-xl border border-sidebar-border/70 bg-card px-4 py-3 dark:border-sidebar-border">
                <p class="text-xs text-muted-foreground">{{ card.label }}</p>
                <p :class="['mt-1 text-xl font-bold tabular-nums', card.color]">
                    {{ card.fmt === 'p' ? card.value.toFixed(1) + '%' : card.value.toLocaleString('id-ID') }}
                </p>
            </div>
        </div>

        <!-- Charts row -->
        <div class="grid gap-3 md:grid-cols-3">
            <!-- Donut -->
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
                <h3 class="mb-1 text-sm font-semibold">Komposisi Status</h3>
                <VueApexCharts v-if="donutSeries.some(v => v > 0)"
                    type="donut" height="260" :options="donutOptions" :series="donutSeries" />
                <div v-else class="flex h-52 items-center justify-center text-sm text-muted-foreground">Tidak ada data</div>
            </div>

            <!-- Bar -->
            <div class="col-span-2 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
                <h3 class="mb-1 text-sm font-semibold">Top {{ TOP_N }} {{ LEVEL_LABELS[filters.level] }} — Progress & Approved %</h3>
                <VueApexCharts v-if="barSeries[0]?.data.length"
                    type="bar" height="260" :options="barOptions" :series="barSeries" />
                <div v-else class="flex h-52 items-center justify-center text-sm text-muted-foreground">Tidak ada data</div>
            </div>
        </div>

        <!-- Trend -->
        <div v-if="trend.length > 1" class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
            <h3 class="mb-1 text-sm font-semibold">Tren Progress Over Time</h3>
            <VueApexCharts type="line" height="200" :options="trendOptions" :series="trendSeries" />
        </div>

        <!-- Breakdown table -->
        <div class="rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <!-- Table header -->
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
                <div>
                    <h3 class="text-sm font-semibold">Rincian per {{ LEVEL_LABELS[filters.level] }}</h3>
                    <p v-if="totalRows" class="mt-0.5 text-xs text-muted-foreground">
                        {{ pageStart }}–{{ pageEnd }} dari {{ totalRows }} area
                        <span v-if="filters.filter_codes.length"> · difilter</span>
                    </p>
                </div>
                <!-- Page size -->
                <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span>Tampilkan</span>
                    <select v-model="pageSize"
                        class="h-7 rounded-md border border-input bg-background px-2 text-xs text-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                        aria-label="Jumlah baris per halaman">
                        <option :value="10">10</option>
                        <option :value="20">20</option>
                        <option :value="50">50</option>
                    </select>
                    <span>baris</span>
                </div>
            </div>

            <!-- Scrollable table -->
            <div class="overflow-x-auto">
                <table class="min-w-max w-full text-sm" role="grid">
                    <thead>
                        <tr class="border-b border-sidebar-border/70 bg-muted/40 text-left text-xs text-muted-foreground dark:border-sidebar-border">
                            <th class="sticky left-0 z-10 cursor-pointer bg-muted/40 px-4 py-2 font-semibold dark:bg-zinc-900/80"
                                @click="toggleSort('label')" scope="col">
                                {{ LEVEL_LABELS[filters.level] }}{{ sortIcon('label') }}
                            </th>
                            <th class="cursor-pointer px-3 py-2 text-right font-semibold" @click="toggleSort('total')" scope="col">
                                Total RT{{ sortIcon('total') }}
                            </th>
                            <th class="cursor-pointer px-3 py-2 font-semibold" @click="toggleSort('progress_pct')" scope="col">
                                Progress{{ sortIcon('progress_pct') }}
                            </th>
                            <th class="cursor-pointer px-3 py-2 font-semibold" @click="toggleSort('approved_pct')" scope="col">
                                Approved{{ sortIcon('approved_pct') }}
                            </th>
                            <th v-for="col in STATUS_COLS" :key="col"
                                class="cursor-pointer px-2 py-2 text-right font-semibold"
                                :title="col" @click="toggleSort(col)" scope="col">
                                {{ STATUS_META[col].short }}{{ sortIcon(col) }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in paginatedRows" :key="row.key"
                            class="border-b border-sidebar-border/30 transition-colors hover:bg-muted/30 dark:border-sidebar-border/20">
                            <!-- Label -->
                            <td class="sticky left-0 z-10 bg-card px-4 py-2.5 dark:bg-zinc-950">
                                <p class="font-medium leading-tight">{{ row.label }}</p>
                                <p v-if="rowContext(row)" class="text-xs text-muted-foreground">{{ rowContext(row) }}</p>
                            </td>
                            <!-- Total -->
                            <td class="px-3 py-2.5 text-right tabular-nums font-medium">
                                {{ row.total.toLocaleString('id-ID') }}
                            </td>
                            <!-- Progress bar -->
                            <td class="px-3 py-2.5 min-w-36">
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted" role="progressbar"
                                        :aria-valuenow="row.progress_pct" aria-valuemin="0" aria-valuemax="100">
                                        <div class="h-full rounded-full bg-emerald-500 transition-all"
                                            :style="{ width: pct(row.progress_pct) + '%' }" />
                                    </div>
                                    <span class="w-12 text-right tabular-nums text-xs font-medium">{{ row.progress_pct }}%</span>
                                </div>
                            </td>
                            <!-- Approved bar -->
                            <td class="px-3 py-2.5 min-w-32">
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted" role="progressbar"
                                        :aria-valuenow="row.approved_pct" aria-valuemin="0" aria-valuemax="100">
                                        <div class="h-full rounded-full bg-blue-500 transition-all"
                                            :style="{ width: pct(row.approved_pct) + '%' }" />
                                    </div>
                                    <span class="w-12 text-right tabular-nums text-xs font-medium">{{ row.approved_pct }}%</span>
                                </div>
                            </td>
                            <!-- Status cols -->
                            <td v-for="col in STATUS_COLS" :key="col"
                                class="px-2 py-2.5 text-right tabular-nums text-xs">
                                <span v-if="row.statuses[col]"
                                    class="inline-block min-w-6 rounded px-1 font-medium"
                                    :style="{ color: STATUS_META[col].color }">
                                    {{ row.statuses[col].toLocaleString('id-ID') }}
                                </span>
                                <span v-else class="text-muted-foreground/40">—</span>
                            </td>
                        </tr>
                        <tr v-if="!paginatedRows.length">
                            <td :colspan="4 + STATUS_COLS.length" class="px-4 py-8 text-center text-sm text-muted-foreground">
                                Tidak ada data untuk filter yang dipilih.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination footer -->
            <div v-if="totalPages > 1"
                class="flex items-center justify-between gap-2 border-t border-sidebar-border/70 px-4 py-2.5 text-xs dark:border-sidebar-border">
                <button :disabled="currentPage === 1"
                    class="rounded-md border border-input bg-background px-3 py-1 font-medium transition-colors hover:bg-muted disabled:opacity-40 focus:outline-none focus:ring-2 focus:ring-ring"
                    @click="goPage(currentPage - 1)" aria-label="Halaman sebelumnya">← Prev</button>

                <div class="flex items-center gap-1">
                    <template v-for="p in totalPages" :key="p">
                        <button v-if="p === 1 || p === totalPages || Math.abs(p - currentPage) <= 1"
                            :class="['min-w-7 rounded-md border px-2 py-1 font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-ring',
                                p === currentPage
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-input bg-background hover:bg-muted']"
                            @click="goPage(p)" :aria-label="`Halaman ${p}`" :aria-current="p === currentPage ? 'page' : undefined">
                            {{ p }}
                        </button>
                        <span v-else-if="p === 2 && currentPage > 3" class="px-1 text-muted-foreground">…</span>
                        <span v-else-if="p === totalPages - 1 && currentPage < totalPages - 2" class="px-1 text-muted-foreground">…</span>
                    </template>
                </div>

                <button :disabled="currentPage === totalPages"
                    class="rounded-md border border-input bg-background px-3 py-1 font-medium transition-colors hover:bg-muted disabled:opacity-40 focus:outline-none focus:ring-2 focus:ring-ring"
                    @click="goPage(currentPage + 1)" aria-label="Halaman berikutnya">Next →</button>
            </div>
        </div>
    </div>
</template>
