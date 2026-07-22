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
type PrelistBasis = 'dynamic' | 'initial';
interface PrelistComparison {
    dynamic_total: number;
    initial_total: number;
    delta: number;
    delta_pct: number;
    matched_subsls: number;
    initial_only_subsls: number;
    initial_without_assignments_subsls: number;
    initial_without_assignments_with_progress_subsls: number;
    initial_without_assignments_missing_progress_subsls: number;
    dynamic_only_subsls: number;
    zero_initial_subsls: number;
    initial_available: boolean;
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
const prelistBasis = ref<PrelistBasis>('dynamic');
const PRELIST_BASIS_OPTIONS: { label: string; value: PrelistBasis }[] = [
    { label: 'Dinamis', value: 'dynamic' },
    { label: 'Awal', value: 'initial' },
];

watch(
    () => props.snapshots,
    (val) => {
        snapshots.value = val;

        if (val.length && (!snapshot.value || val[0] !== snapshots.value[0])) {
            snapshot.value = val[0];
        }
    },
);
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
const trend = ref<TrendPoint[]>([]);
const prelistComparison = ref<PrelistComparison>({
    dynamic_total: 0,
    initial_total: 0,
    delta: 0,
    delta_pct: 0,
    matched_subsls: 0,
    initial_only_subsls: 0,
    initial_without_assignments_subsls: 0,
    initial_without_assignments_with_progress_subsls: 0,
    initial_without_assignments_missing_progress_subsls: 0,
    dynamic_only_subsls: 0,
    zero_initial_subsls: 0,
    initial_available: false,
});
const prelistBasisLabel = computed(() =>
    prelistBasis.value === 'initial' ? 'Prelist Awal' : 'Prelist Dinamis',
);
const prelistInitialMissing = computed(
    () =>
        prelistBasis.value === 'initial' &&
        !prelistComparison.value.initial_available,
);

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
            `/api/ringkasan-data?snapshot=${encodeURIComponent(snapshot.value)}&prelist_basis=${prelistBasis.value}`,
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
        trend.value = data.trend;
        prelistComparison.value = data.prelist_comparison;
    } finally {
        loading.value = false;
    }
}

watch([snapshot, prelistBasis], fetchData);
onMounted(fetchData);

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
    'COMPLETED BY Admin Kabupaten',
    'EDITED BY Admin Kabupaten',
    'REJECTED BY Admin Kabupaten',
    'REVOKED BY Admin Kabupaten',
];
const STATUS_LABELS = STATUS_COLS;
const STATUS_COLORS = computed(() =>
    STATUS_COLS.map((c) => {
        if (c === 'OPEN') {
            return isDark.value ? '#71717a' : '#a1a1aa';
        }

        return (
            (
                {
                    DRAFT: '#FFD45A',
                    'SUBMITTED BY Pencacah': '#FF8B5A',
                    'APPROVED BY Pengawas': '#22c55e',
                    'REJECTED BY Pengawas': '#FF5A5A',
                    'EDITED BY Pengawas': '#FFA95A',
                    'REVOKED BY Pengawas': '#dc2626',
                    'SUBMITTED RESPONDENT': '#a78bfa',
                    'COMPLETED BY Admin Kabupaten': '#14b8a6',
                    'EDITED BY Admin Kabupaten': '#f97316',
                    'REJECTED BY Admin Kabupaten': '#be123c',
                    'REVOKED BY Admin Kabupaten': '#7f1d1d',
                } as Record<string, string>
            )[c] ?? '#6b7280'
        );
    }),
);

const statusRows = computed(() =>
    STATUS_COLS.map((col, i) => ({
        label: STATUS_LABELS[i],
        color: STATUS_COLORS.value[i],
        count: statusTotals.value[col] ?? 0,
        pct:
            metrics.value.total_assignment > 0
                ? ((statusTotals.value[col] ?? 0) /
                      metrics.value.total_assignment) *
                  100
                : 0,
    })),
);

// ── chart: trend ──────────────────────────────────────────────────────────
const trendSeries = computed(() => [
    { name: 'Submit %', data: trend.value.map((t) => t.progress_pct) },
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
    const maxVal = Math.max(
        0,
        ...trend.value.map((t) =>
            Math.max(t.progress_pct, t.submitted_pct, t.approved_pct),
        ),
    );

    return Math.ceil((maxVal + 5) / 5) * 5 || 20;
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
    colors: ['#FFA95A', '#3b82f6', '#22c55e'],
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
    <div v-else class="flex flex-col gap-3 p-4">
        <!-- header kabupaten -->
        <div
            class="rounded-xl border border-sidebar-border/70 bg-card px-4 py-2.5 shadow-sm dark:border-sidebar-border"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p
                        class="text-xs font-semibold tracking-widest text-muted-foreground uppercase"
                    >
                        {{ provName }}
                    </p>
                    <h1 class="text-xl font-bold tracking-tight">
                        KABUPATEN {{ kabName }}
                    </h1>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Ringkasan data dan submit pencacahan
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
                    <div
                        class="flex overflow-hidden rounded-md border border-input bg-background text-xs"
                    >
                        <button
                            v-for="option in PRELIST_BASIS_OPTIONS"
                            :key="option.value"
                            type="button"
                            :class="[
                                'px-2.5 py-1 font-medium transition-colors',
                                prelistBasis === option.value
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:bg-muted',
                            ]"
                            @click="prelistBasis = option.value"
                        >
                            {{ option.label }}
                        </button>
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

        <div
            v-if="prelistInitialMissing"
            class="rounded-xl border border-amber-400/50 bg-amber-500/10 px-4 py-3 text-sm text-amber-800 shadow-sm dark:text-amber-200"
        >
            <span class="font-semibold">Prelist awal belum tersedia.</span>
            Ringkasan tetap memakai Prelist Dinamis sampai data awal diimpor.
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
                        label: `Total Assignment (${prelistBasisLabel})`,
                        value: metrics.total_assignment,
                        fmt: 'n',
                        color: 'text-foreground',
                        ring: '',
                    },
                ]"
                :key="card.label"
                class="rounded-xl border border-sidebar-border/70 bg-card px-3 py-2 shadow-sm dark:border-sidebar-border"
            >
                <p class="text-xs text-muted-foreground">{{ card.label }}</p>
                <p :class="['mt-1 text-xl font-bold tabular-nums', card.color]">
                    {{ card.value.toLocaleString('id-ID') }}
                </p>
            </div>
        </div>

        <div
            class="grid gap-3 rounded-2xl border border-sky-300/35 bg-gradient-to-r from-sky-500/10 via-card to-card p-3 shadow-sm md:grid-cols-[1.2fr_repeat(4,1fr)] dark:border-sky-500/20"
        >
            <div class="flex min-w-0 flex-col justify-center">
                <p
                    class="text-[10px] font-bold tracking-[0.22em] text-sky-700 uppercase dark:text-sky-300"
                >
                    Gap Prelist
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Basis aktif: {{ prelistBasisLabel }}. Cakupan assignments
                    dipisahkan dari progress agar kualitas data terbaca jelas.
                </p>
            </div>
            <div
                v-for="item in [
                    {
                        label: 'Dinamis',
                        value: prelistComparison.dynamic_total,
                        suffix: 'assignment',
                    },
                    {
                        label: 'Awal',
                        value: prelistComparison.initial_total,
                        suffix: prelistComparison.initial_available
                            ? 'assignment'
                            : 'belum impor',
                    },
                    {
                        label: 'Selisih',
                        value: prelistComparison.delta,
                        suffix: `${prelistComparison.delta_pct}%`,
                    },
                    {
                        label: 'Fallback Progress',
                        value: prelistComparison.initial_without_assignments_with_progress_subsls,
                        suffix: `${prelistComparison.initial_only_subsls} belum masuk basis`,
                    },
                ]"
                :key="item.label"
                class="rounded-xl border border-white/50 bg-background/70 px-3 py-2 shadow-sm dark:border-white/10"
            >
                <p class="text-xs text-muted-foreground">{{ item.label }}</p>
                <p class="mt-0.5 text-lg font-bold tabular-nums">
                    {{ item.value.toLocaleString('id-ID') }}
                </p>
                <p class="text-[11px] text-muted-foreground">
                    {{ item.suffix }}
                </p>
            </div>
        </div>

        <!-- Metric cards row 2: progress -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div
                v-for="card in [
                    {
                        label: '% Submit',
                        value: metrics.progress_pct,
                        hex: '#FFA95A',
                        bar: 'bg-[#FFA95A]',
                        ring: 'border-[#FFA95A]/40 bg-[#FFA95A]/8 dark:bg-[#FFA95A]/12',
                        tooltip:
                            '% Submit = jumlah aktual semua status selain OPEN dan DRAFT ÷ Total Assignment basis aktif × 100%',
                    },
                    {
                        label: 'Submitted',
                        value: metrics.submitted_pct,
                        hex: '#3b82f6',
                        bar: 'bg-blue-500',
                        ring: 'border-blue-500/30 bg-blue-500/5 dark:bg-blue-500/10',
                        tooltip: '',
                    },
                    {
                        label: 'Approved',
                        value: metrics.approved_pct,
                        hex: '#22c55e',
                        bar: 'bg-[#22c55e]',
                        ring: 'border-green-500/30 bg-green-500/5 dark:bg-green-500/10',
                        tooltip: '',
                    },
                    {
                        label: 'Rejected',
                        value: metrics.rejected_pct,
                        hex: '#FF5A5A',
                        bar: 'bg-[#FF5A5A]',
                        ring: 'border-[#FF5A5A]/40 bg-[#FF5A5A]/8 dark:bg-[#FF5A5A]/12',
                        tooltip: '',
                    },
                ]"
                :key="card.label"
                :class="['rounded-xl border px-4 py-2.5', card.ring]"
            >
                <p
                    class="flex items-center gap-1 text-xs text-muted-foreground"
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
                    class="mt-1 text-2xl font-bold tabular-nums"
                    :style="{ color: (card as any).hex }"
                >
                    {{ card.value.toFixed(1) }}%
                </p>
                <!-- Progress bar -->
                <div
                    class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-muted"
                >
                    <div
                        class="h-full rounded-full transition-all"
                        :class="card.bar"
                        :style="{ width: pct(card.value) + '%' }"
                    />
                </div>
            </div>
        </div>

        <!-- Formula explanation -->
        <div
            class="rounded-lg border border-sidebar-border/50 bg-muted/40 px-3 py-2 text-xs text-muted-foreground dark:border-sidebar-border"
        >
            <span class="font-semibold text-foreground/70"
                >Cara penghitungan:</span
            >
            <span class="ml-1.5">
                Total memakai {{ prelistBasisLabel }}.
                <span class="mx-1 opacity-40">·</span>
                <span class="font-medium text-[#FFA95A]">% Submit</span> =
                status aktual selain OPEN/DRAFT ÷ Total.
                <span class="mx-1 opacity-40">·</span>
                <span class="font-medium text-blue-500">Submitted</span> =
                Diserahkan Pencacah ÷ Total.
                <span class="mx-1 opacity-40">·</span>
                <span class="font-medium text-green-500">Approved</span> =
                Disetujui Pengawas ÷ Total.
                <span class="mx-1 opacity-40">·</span>
                <span class="font-medium text-red-400">Rejected</span> = Ditolak
                Pengawas ÷ Total.
            </span>
        </div>

        <!-- Charts row -->
        <div class="grid gap-3 md:grid-cols-3">
            <!-- Status Table -->
            <div
                class="flex flex-col rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border"
            >
                <h3 class="mb-2 shrink-0 text-sm font-semibold">
                    Komposisi Status
                </h3>
                <div
                    v-if="Object.keys(statusTotals).length > 0"
                    class="min-h-0 flex-1 overflow-y-auto"
                >
                    <table class="w-full text-xs">
                        <tbody>
                            <tr
                                v-for="(row, i) in statusRows"
                                :key="i"
                                :class="[
                                    'border-b border-border/40 last:border-0',
                                    row.count === 0 ? 'opacity-40' : '',
                                ]"
                            >
                                <td class="py-1.5 pr-2">
                                    <div class="flex items-center gap-1.5">
                                        <span
                                            class="inline-block h-2.5 w-2.5 shrink-0 rounded-full"
                                            :style="{
                                                backgroundColor: row.color,
                                            }"
                                        />
                                        <span class="text-foreground">{{
                                            row.label
                                        }}</span>
                                    </div>
                                </td>
                                <td
                                    class="py-1.5 pr-2 text-right font-medium tabular-nums"
                                >
                                    {{ row.count.toLocaleString('id-ID') }}
                                </td>
                                <td
                                    class="w-12 py-1.5 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ row.pct.toFixed(1) }}%
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-border">
                            <tr>
                                <td
                                    class="pt-2 text-xs font-semibold text-muted-foreground"
                                >
                                    Total
                                </td>
                                <td
                                    class="pt-2 text-right text-xs font-bold tabular-nums"
                                >
                                    {{
                                        metrics.total_assignment.toLocaleString(
                                            'id-ID',
                                        )
                                    }}
                                </td>
                                <td
                                    class="pt-2 text-right text-xs text-muted-foreground"
                                >
                                    100%
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div
                    v-else
                    class="flex flex-1 items-center justify-center text-sm text-muted-foreground"
                >
                    Tidak ada data
                </div>
            </div>

            <!-- Trend -->
            <div
                class="col-span-2 flex flex-col rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border"
            >
                <h3 class="mb-1 shrink-0 text-sm font-semibold">
                    Tren Submit Over Time
                </h3>
                <VueApexCharts
                    v-if="trend.length >= 1"
                    type="line"
                    :height="360"
                    :options="trendOptions"
                    :series="trendSeries"
                />
                <div
                    v-else
                    class="flex items-center justify-center text-sm text-muted-foreground"
                    style="height: 360px"
                >
                    Tidak ada data tren
                </div>
                <p
                    v-if="trend.length === 1"
                    class="mt-1 shrink-0 text-center text-xs text-muted-foreground"
                >
                    Hanya 1 snapshot — tambah snapshot lebih untuk melihat tren
                </p>
            </div>
        </div>
    </div>
</template>
