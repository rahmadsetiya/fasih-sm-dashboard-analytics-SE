<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Sun, Moon } from '@lucide/vue';
import { useDark, useWindowSize } from '@vueuse/core';
import { ref, computed, watch, onMounted } from 'vue';
import VueApexCharts from 'vue3-apexcharts';

const props = defineProps<{
    snapshots: string[];
    db_ready: boolean;
}>();

// ── types ─────────────────────────────────────────────────────────────────
interface RingkasanMetrics {
    total_kec: number;
    total_desa: number;
    total_sls: number;
    total_subsls: number;
    total_pengawas: number;
    total_pencacah: number;
    total_assignment: number;
    progress_pct: number;
    approved_pct: number;
    submitted_pct: number;
    rejected_pct: number;
}
interface KecRow {
    kdkec: string;
    nmkec: string;
    total_desa: number;
    total: number;
    progress_pct: number;
    approved_pct: number;
    statuses: Record<string, number>;
}
interface TrendPoint {
    snapshot_at: string;
    progress_pct: number;
    submitted_pct: number;
    approved_pct: number;
    total: number;
}

// ── state ─────────────────────────────────────────────────────────────────
const snapshots = ref<string[]>(props.snapshots);
const snapshot = ref(props.snapshots[0] ?? '');
const loading = ref(false);

const kabName = ref('—');
const provName = ref('—');
const metrics = ref<RingkasanMetrics>({
    total_kec: 0,
    total_desa: 0,
    total_sls: 0,
    total_subsls: 0,
    total_pengawas: 0,
    total_pencacah: 0,
    total_assignment: 0,
    progress_pct: 0,
    approved_pct: 0,
    submitted_pct: 0,
    rejected_pct: 0,
});
const statusTotals = ref<Record<string, number>>({});
const kecamatan = ref<KecRow[]>([]);
const trend = ref<TrendPoint[]>([]);

const isDark = useDark();
const chartBg = computed(() => (isDark.value ? '#18181b' : '#ffffff'));
const chartMode = computed(() =>
    isDark.value ? ('dark' as const) : ('light' as const),
);
const { width: vw } = useWindowSize();
const cFontXs = computed(
    () =>
        `${Math.max(10, Math.min(14, Math.round(10 + (vw.value - 1000) / 200)))}px`,
);
const cFontSm = computed(
    () =>
        `${Math.max(11, Math.min(15, Math.round(11 + (vw.value - 1000) / 200)))}px`,
);
const cFontMd = computed(
    () =>
        `${Math.max(12, Math.min(16, Math.round(12 + (vw.value - 1000) / 200)))}px`,
);

// ── fetch ─────────────────────────────────────────────────────────────────
async function fetchData() {
    if (!snapshot.value) {
        return;
    }

    loading.value = true;

    try {
        const res = await fetch(
            `/api/ringkasan-data?snapshot=${encodeURIComponent(snapshot.value)}`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );
        const data = await res.json();
        kabName.value = data.kab_name;
        provName.value = data.prov_name;
        metrics.value = data.metrics;
        statusTotals.value = data.status_totals;
        kecamatan.value = data.kecamatan;
        trend.value = data.trend;
    } finally {
        loading.value = false;
    }
}

watch(snapshot, fetchData);
onMounted(fetchData);

// ── sort ──────────────────────────────────────────────────────────────────
const sortCol = ref('total');
const sortDir = ref<'asc' | 'desc'>('desc');

function toggleSort(col: string) {
    if (sortCol.value === col) {
        sortDir.value = sortDir.value === 'desc' ? 'asc' : 'desc';
    } else {
        sortCol.value = col;
        sortDir.value = 'desc';
    }
}
const sortIcon = (col: string) =>
    sortCol.value !== col ? '' : sortDir.value === 'desc' ? ' ↓' : ' ↑';

const sortedKec = computed(() => {
    const copy = [...kecamatan.value];
    copy.sort((a, b) => {
        const va = (a as any)[sortCol.value] as number;
        const vb = (b as any)[sortCol.value] as number;

        return sortDir.value === 'desc' ? vb - va : va - vb;
    });

    return copy;
});

// ── chart: donut ──────────────────────────────────────────────────────────
const STATUS_COLS = [
    'OPEN',
    'DRAFT',
    'SUBMITTED BY Pencacah',
    'APPROVED BY Pengawas',
    'REJECTED BY Pengawas',
    'EDITED BY Pengawas',
    'REVOKED BY Pengawas',
    'SUBMITTED RESPONDENT',
];
const STATUS_LABELS = [
    'Belum diisi',
    'Sedang diisi',
    'Diserahkan Pencacah',
    'Disetujui Pengawas',
    'Ditolak Pengawas',
    'Diedit Pengawas',
    'Dicabut Pengawas',
    'Submit Responden',
];
const STATUS_COLORS = computed(() =>
    STATUS_COLS.map((c) => {
        if (c === 'OPEN') {
            return isDark.value ? '#71717a' : '#a1a1aa';
        }

        return (
            (
                {
                    DRAFT: '#f97316',
                    'SUBMITTED BY Pencacah': '#2563eb',
                    'APPROVED BY Pengawas': '#16a34a',
                    'REJECTED BY Pengawas': '#dc2626',
                    'EDITED BY Pengawas': '#d97706',
                    'REVOKED BY Pengawas': '#be185d',
                    'SUBMITTED RESPONDENT': '#4f46e5',
                } as Record<string, string>
            )[c] ?? '#6b7280'
        );
    }),
);

const donutWidth = computed(() =>
    Math.min(260, vw.value >= 768 ? (vw.value - 48) / 3 : vw.value - 32),
);
const donutInnerR = computed(() => (donutWidth.value / 2) * 0.62);
const donutLabelFont = computed(
    () => `${Math.max(8, Math.min(11, Math.round(donutInnerR.value / 7)))}px`,
);
const donutValueFont = computed(
    () =>
        `${Math.max(11, Math.min(18, Math.round(donutInnerR.value / 4.5)))}px`,
);

const donutSeries = computed(() =>
    STATUS_COLS.map((c) => statusTotals.value[c] ?? 0),
);
const donutOptions = computed(() => ({
    chart: {
        type: 'donut' as const,
        background: chartBg.value,
        toolbar: { show: false },
    },
    theme: { mode: chartMode.value },
    labels: STATUS_LABELS,
    colors: STATUS_COLORS.value,
    legend: { position: 'bottom' as const, fontSize: cFontSm.value },
    dataLabels: { enabled: false },
    plotOptions: {
        pie: {
            donut: {
                size: '62%',
                labels: {
                    show: true,
                    name: {
                        show: true,
                        fontSize: donutLabelFont.value,
                        offsetY: -4,
                    },
                    value: {
                        show: true,
                        fontSize: donutValueFont.value,
                        offsetY: 4,
                        formatter: (v: string) =>
                            Number(v).toLocaleString('id-ID'),
                    },
                    total: {
                        show: true,
                        label: 'Total',
                        fontSize: donutLabelFont.value,
                        formatter: () =>
                            metrics.value.total_assignment.toLocaleString(
                                'id-ID',
                            ),
                    },
                },
            },
        },
    },
    stroke: { width: 0 },
    tooltip: { y: { formatter: (v: number) => v.toLocaleString('id-ID') } },
}));

// ── chart: trend ──────────────────────────────────────────────────────────
const trendSeries = computed(() => [
    { name: 'Progress %', data: trend.value.map((t) => t.progress_pct) },
    { name: 'Submitted %', data: trend.value.map((t) => t.submitted_pct) },
    { name: 'Approved %', data: trend.value.map((t) => t.approved_pct) },
]);
const trendCategories = computed(() =>
    trend.value.map((t) => {
        const d = new Date(t.snapshot_at);

        return `${d.getDate().toString().padStart(2, '0')}/${(d.getMonth() + 1).toString().padStart(2, '0')} ${d.getHours().toString().padStart(2, '0')}:${d.getMinutes().toString().padStart(2, '0')}`;
    }),
);
const trendMax = computed(() => {
    if (trend.value.length <= 1) {
        const maxVal = Math.max(
            0,
            ...trend.value.map((t) =>
                Math.max(t.progress_pct, t.submitted_pct, t.approved_pct),
            ),
        );

        return Math.ceil((maxVal + 5) / 5) * 5 || 20;
    }

    return 100;
});
const trendOptions = computed(() => ({
    chart: {
        type: 'line' as const,
        background: chartBg.value,
        toolbar: { show: false },
        zoom: { enabled: false },
    },
    theme: { mode: chartMode.value },
    stroke: { curve: 'smooth' as const, width: 2.5 },
    colors: ['#059669', '#7c3aed', '#1d4ed8'],
    xaxis: {
        categories: trendCategories.value,
        labels: { rotate: -30, style: { fontSize: cFontXs.value } },
    },
    yaxis: {
        max: trendMax.value,
        labels: {
            formatter: (v: number) => v + '%',
            style: { fontSize: cFontSm.value },
        },
    },
    markers: { size: 5 },
    tooltip: { y: { formatter: (v: number) => v.toFixed(1) + '%' } },
    legend: { position: 'top' as const, fontSize: cFontMd.value },
}));

// ── helpers ───────────────────────────────────────────────────────────────
function fmtSnap(s: string) {
    return new Date(s).toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
function pct(v: number) {
    return Math.min(100, Math.max(0, v));
}

const activeStatusCols = computed(() =>
    STATUS_COLS.filter((col) =>
        kecamatan.value.some((r) => (r.statuses[col] ?? 0) > 0),
    ),
);
const STATUS_META: Record<string, { short: string; color: string }> = {
    OPEN: { short: 'Open', color: '' },
    DRAFT: { short: 'Draft', color: '#f97316' },
    'SUBMITTED BY Pencacah': { short: 'Sub.P', color: '#2563eb' },
    'APPROVED BY Pengawas': { short: 'App.P', color: '#16a34a' },
    'REJECTED BY Pengawas': { short: 'Rej.P', color: '#dc2626' },
    'EDITED BY Pengawas': { short: 'Edit.P', color: '#d97706' },
    'REVOKED BY Pengawas': { short: 'Rev.P', color: '#be185d' },
    'SUBMITTED RESPONDENT': { short: 'Sub.R', color: '#4f46e5' },
};
</script>

<template>
    <Head title="Ringkasan Kabupaten" />

    <!-- empty state -->
    <div
        v-if="!db_ready"
        class="flex h-full flex-1 flex-col items-center justify-center gap-4 p-8 text-center"
    >
        <div class="rounded-full bg-muted p-6">
            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="size-12 text-muted-foreground"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.5"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 5.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125"
                />
            </svg>
        </div>
        <div>
            <h2 class="text-lg font-semibold">Database belum diimport</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Upload file
                <code class="rounded bg-muted px-1 text-xs">fasih.db</code>
                melalui sidebar.
            </p>
        </div>
    </div>

    <!-- main -->
    <div v-else class="flex h-full flex-1 flex-col gap-3 overflow-x-auto p-4">
        <!-- header kabupaten -->
        <div
            class="rounded-xl border border-sidebar-border/70 bg-card px-5 py-4 dark:border-sidebar-border"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p
                        class="text-xs font-semibold tracking-widest text-muted-foreground uppercase"
                    >
                        {{ provName }}
                    </p>
                    <h1 class="text-2xl font-bold tracking-tight">
                        KABUPATEN {{ kabName }}
                    </h1>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Ringkasan data dan progress pencacahan
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <!-- Snapshot selector -->
                    <div class="flex items-center gap-1.5">
                        <span class="text-xs font-medium text-muted-foreground"
                            >Snapshot</span
                        >
                        <select
                            v-model="snapshot"
                            class="h-7 rounded-md border border-input bg-background px-2 text-xs text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                        >
                            <option v-for="s in snapshots" :key="s" :value="s">
                                {{ fmtSnap(s) }}
                            </option>
                        </select>
                    </div>
                    <span
                        v-if="loading"
                        class="animate-pulse text-xs text-muted-foreground"
                        >Memuat…</span
                    >
                    <!-- Theme toggle -->
                    <button
                        class="flex items-center justify-center rounded-md border border-input bg-background p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                        @click="isDark = !isDark"
                    >
                        <Sun v-if="isDark" class="size-4" />
                        <Moon v-else class="size-4" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Metric cards row 1: geographic counts + assignment -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
            <div
                v-for="card in [
                    {
                        label: 'Kecamatan',
                        value: metrics.total_kec,
                        fmt: 'n',
                        color: 'text-blue-600 dark:text-blue-400',
                        ring: '',
                    },
                    {
                        label: 'Desa',
                        value: metrics.total_desa,
                        fmt: 'n',
                        color: 'text-cyan-600 dark:text-cyan-400',
                        ring: '',
                    },
                    {
                        label: 'SLS',
                        value: metrics.total_sls,
                        fmt: 'n',
                        color: 'text-sky-600 dark:text-sky-400',
                        ring: '',
                    },
                    {
                        label: 'SubSLS',
                        value: metrics.total_subsls,
                        fmt: 'n',
                        color: 'text-indigo-600 dark:text-indigo-400',
                        ring: '',
                    },
                    {
                        label: 'Pengawas',
                        value: metrics.total_pengawas,
                        fmt: 'n',
                        color: 'text-violet-600 dark:text-violet-400',
                        ring: '',
                    },
                    {
                        label: 'Pencacah',
                        value: metrics.total_pencacah,
                        fmt: 'n',
                        color: 'text-purple-600 dark:text-purple-400',
                        ring: '',
                    },
                    {
                        label: 'Total Assignment',
                        value: metrics.total_assignment,
                        fmt: 'n',
                        color: 'text-foreground',
                        ring: '',
                    },
                ]"
                :key="card.label"
                class="rounded-xl border border-sidebar-border/70 bg-card px-4 py-3 dark:border-sidebar-border"
            >
                <p class="text-xs text-muted-foreground">{{ card.label }}</p>
                <p
                    :class="[
                        'mt-1 text-2xl font-bold tabular-nums',
                        card.color,
                    ]"
                >
                    {{ card.value.toLocaleString('id-ID') }}
                </p>
            </div>
        </div>

        <!-- Metric cards row 2: progress -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div
                v-for="card in [
                    {
                        label: 'Progress',
                        value: metrics.progress_pct,
                        color: 'text-orange-600 dark:text-orange-400',
                        bar: 'bg-orange-500',
                        ring: 'border-orange-500/30 bg-orange-500/5 dark:bg-orange-500/10',
                        tooltip: 'Progress = (Total − OPEN) ÷ Total × 100%',
                    },
                    {
                        label: 'Submitted',
                        value: metrics.submitted_pct,
                        color: 'text-blue-600 dark:text-blue-400',
                        bar: 'bg-blue-500',
                        ring: 'border-blue-500/30 bg-blue-500/5 dark:bg-blue-500/10',
                        tooltip: '',
                    },
                    {
                        label: 'Approved',
                        value: metrics.approved_pct,
                        color: 'text-green-600 dark:text-green-400',
                        bar: 'bg-green-500',
                        ring: 'border-green-500/30 bg-green-500/5 dark:bg-green-500/10',
                        tooltip: '',
                    },
                    {
                        label: 'Rejected',
                        value: metrics.rejected_pct,
                        color: 'text-red-600 dark:text-red-400',
                        bar: 'bg-red-500',
                        ring: 'border-red-500/30 bg-red-500/5 dark:bg-red-500/10',
                        tooltip: '',
                    },
                ]"
                :key="card.label"
                :class="['rounded-xl border px-5 py-4', card.ring]"
            >
                <p
                    class="flex items-center gap-1 text-sm text-muted-foreground"
                    :title="card.tooltip || undefined"
                >
                    {{ card.label }}
                    <span
                        v-if="card.tooltip"
                        class="cursor-help text-xs text-muted-foreground/50"
                        >ⓘ</span
                    >
                </p>
                <p
                    :class="[
                        'mt-1 text-3xl font-bold tabular-nums',
                        card.color,
                    ]"
                >
                    {{ card.value.toFixed(1) }}%
                </p>
                <!-- Progress bar -->
                <div
                    class="mt-2 h-2 w-full overflow-hidden rounded-full bg-muted"
                >
                    <div
                        class="h-full rounded-full transition-all"
                        :class="card.bar"
                        :style="{ width: pct(card.value) + '%' }"
                    />
                </div>
            </div>
        </div>

        <!-- Charts row -->
        <div class="grid gap-3 md:grid-cols-3">
            <!-- Donut -->
            <div
                class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
            >
                <h3 class="mb-1 text-sm font-semibold">Komposisi Status</h3>
                <VueApexCharts
                    v-if="donutSeries.some((v) => v > 0)"
                    type="donut"
                    height="260"
                    :options="donutOptions"
                    :series="donutSeries"
                />
                <div
                    v-else
                    class="flex h-52 items-center justify-center text-sm text-muted-foreground"
                >
                    Tidak ada data
                </div>
            </div>

            <!-- Trend -->
            <div
                class="col-span-2 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
            >
                <h3 class="mb-1 text-sm font-semibold">
                    Tren Progress Over Time
                </h3>
                <VueApexCharts
                    v-if="trend.length >= 1"
                    type="line"
                    :height="trend.length === 1 ? 120 : 260"
                    :options="trendOptions"
                    :series="trendSeries"
                />
                <div
                    v-else
                    class="flex h-52 items-center justify-center text-sm text-muted-foreground"
                >
                    Tidak ada data tren
                </div>
                <p
                    v-if="trend.length === 1"
                    class="mt-1 text-center text-xs text-muted-foreground"
                >
                    Hanya 1 snapshot — tambah snapshot lebih untuk melihat tren
                </p>
            </div>
        </div>

        <!-- Kecamatan table -->
        <div
            class="rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
        >
            <div
                class="flex items-center justify-between border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border"
            >
                <h3 class="text-sm font-semibold">Rincian per Kecamatan</h3>
                <span class="text-xs text-muted-foreground"
                    >{{ kecamatan.length }} kecamatan</span
                >
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-max text-sm" role="grid">
                    <thead>
                        <tr
                            class="border-b border-sidebar-border/70 bg-muted/40 text-left text-xs text-muted-foreground dark:border-sidebar-border"
                        >
                            <th
                                class="cursor-pointer px-4 py-2 font-semibold"
                                @click="toggleSort('nmkec')"
                                scope="col"
                            >
                                Kecamatan{{ sortIcon('nmkec') }}
                            </th>
                            <th
                                class="cursor-pointer px-3 py-2 text-right font-semibold"
                                @click="toggleSort('total_desa')"
                                scope="col"
                            >
                                Desa{{ sortIcon('total_desa') }}
                            </th>
                            <th
                                class="cursor-pointer px-3 py-2 text-right font-semibold"
                                @click="toggleSort('total')"
                                scope="col"
                            >
                                Assignment{{ sortIcon('total') }}
                            </th>
                            <th
                                class="cursor-pointer px-3 py-2 font-semibold"
                                @click="toggleSort('progress_pct')"
                                scope="col"
                            >
                                Progress{{ sortIcon('progress_pct') }}
                            </th>
                            <th
                                class="cursor-pointer px-3 py-2 font-semibold"
                                @click="toggleSort('approved_pct')"
                                scope="col"
                            >
                                Approved{{ sortIcon('approved_pct') }}
                            </th>
                            <th
                                v-for="col in activeStatusCols"
                                :key="col"
                                class="px-2 py-2 text-right font-semibold"
                                :title="col"
                                scope="col"
                            >
                                {{ STATUS_META[col]?.short ?? col }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in sortedKec"
                            :key="row.kdkec"
                            class="border-b border-sidebar-border/30 hover:bg-muted/30 dark:border-sidebar-border/20"
                        >
                            <td class="px-4 py-2.5 font-medium">
                                {{ row.nmkec }}
                            </td>
                            <td
                                class="px-3 py-2.5 text-right text-muted-foreground tabular-nums"
                            >
                                {{ row.total_desa }}
                            </td>
                            <td
                                class="px-3 py-2.5 text-right font-medium tabular-nums"
                            >
                                {{ row.total.toLocaleString('id-ID') }}
                            </td>
                            <!-- Progress bar -->
                            <td class="min-w-36 px-3 py-2.5">
                                <div class="flex items-center gap-2">
                                    <div
                                        class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                                        role="progressbar"
                                        :aria-valuenow="row.progress_pct"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                    >
                                        <div
                                            class="h-full rounded-full bg-emerald-500 transition-all"
                                            :style="{
                                                width:
                                                    pct(row.progress_pct) + '%',
                                            }"
                                        />
                                    </div>
                                    <span
                                        class="w-12 text-right text-xs font-medium tabular-nums"
                                        >{{ row.progress_pct }}%</span
                                    >
                                </div>
                            </td>
                            <!-- Approved bar -->
                            <td class="min-w-32 px-3 py-2.5">
                                <div class="flex items-center gap-2">
                                    <div
                                        class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                                        role="progressbar"
                                        :aria-valuenow="row.approved_pct"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                    >
                                        <div
                                            class="h-full rounded-full bg-blue-500 transition-all"
                                            :style="{
                                                width:
                                                    pct(row.approved_pct) + '%',
                                            }"
                                        />
                                    </div>
                                    <span
                                        class="w-12 text-right text-xs font-medium tabular-nums"
                                        >{{ row.approved_pct }}%</span
                                    >
                                </div>
                            </td>
                            <!-- Status cols -->
                            <td
                                v-for="col in activeStatusCols"
                                :key="col"
                                class="px-2 py-2.5 text-right text-xs tabular-nums"
                            >
                                <span
                                    v-if="row.statuses[col]"
                                    class="inline-block min-w-6 rounded px-1 font-medium"
                                    :style="
                                        STATUS_META[col]?.color
                                            ? { color: STATUS_META[col].color }
                                            : {}
                                    "
                                >
                                    {{
                                        row.statuses[col].toLocaleString(
                                            'id-ID',
                                        )
                                    }}
                                </span>
                                <span v-else class="text-muted-foreground/40"
                                    >—</span
                                >
                            </td>
                        </tr>
                        <tr v-if="!kecamatan.length">
                            <td
                                :colspan="5 + activeStatusCols.length"
                                class="px-4 py-8 text-center text-sm text-muted-foreground"
                            >
                                Tidak ada data untuk snapshot yang dipilih.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
