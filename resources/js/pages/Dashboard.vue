<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Sun, Moon, Pencil, X, ChevronDown, MapPin } from '@lucide/vue';
import { useDark, useWindowSize } from '@vueuse/core';
import MultiSelect from 'primevue/multiselect';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import { ref, reactive, watch, computed, onMounted } from 'vue';

import VueApexCharts from 'vue3-apexcharts';

import MobileRankingBars from '../components/dashboard/MobileRankingBars.vue';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    snapshots: string[];
    db_ready: boolean;
}>();

// ── types ─────────────────────────────────────────────────────────────────
type Role = 'pengawas' | 'pencacah';
type Level = 'kec' | 'desa' | 'sls' | 'subsls' | 'by_pengawas' | 'by_pencacah';

interface Metrics {
    total_petugas: number;
    total_kec: number;
    total_desa: number;
    total_assignment: number;
    progress_pct: number;
    approved_pct: number;
    submitted_pct: number;
    rejected_pct: number;
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
    lapangan_total: number;
    lapangan_pct: number;
    approved_pct: number;
    statuses: Record<string, number>;
    nmkec?: string;
    nmdesa?: string;
    nmsls?: string;
    kec_count?: number;
    desa_count?: number;
}
interface FilterOption {
    code: string;
    label: string;
    total: number;
    kec?: string;
    kec_code?: string;
    desa?: string;
    desa_code?: string;
}
interface FilterOptions {
    prov: { code: string; label: string }[];
    kab: { code: string; label: string }[];
    kec: FilterOption[];
    desa: FilterOption[] | null;
    sls: FilterOption[] | null;
}

// ── state ─────────────────────────────────────────────────────────────────
const snapshots = ref<string[]>(props.snapshots);
const loading = ref(false);

const filters = reactive({
    snapshot: props.snapshots[0] ?? '',
    role: 'pengawas' as Role,
    level: 'kec' as Level,
    filter_kec: [] as string[],
    filter_desa: [] as string[],
    filter_sls: [] as string[],
});

// When Inertia reloads props (e.g. after DB import), sync snapshots and auto-fetch
watch(
    () => props.snapshots,
    (val) => {
        snapshots.value = val;

        if (
            val.length &&
            (!filters.snapshot || val[0] !== snapshots.value[0])
        ) {
            filters.snapshot = val[0];
        }
    },
    { deep: true },
);

const metrics = ref<Metrics>({
    total_petugas: 0,
    total_kec: 0,
    total_desa: 0,
    total_assignment: 0,
    progress_pct: 0,
    approved_pct: 0,
    submitted_pct: 0,
    rejected_pct: 0,
});
const statusTotals = ref<Record<string, number>>({});
const breakdown = ref<BreakdownRow[]>([]);
const trend = ref<TrendPoint[]>([]);
const filterOptions = ref<FilterOptions | null>(null);

// ── compare mode ──────────────────────────────────────────────────────────
const compareMode = ref(false);
const compareSnapshot = ref('');
const compareData = ref<BreakdownRow[]>([]);
const compareLoading = ref(false);
const otherSnapshots = computed(() =>
    snapshots.value.filter((s) => s !== filters.snapshot),
);

// compare chart mode: top15 auto vs custom region selection
const compareChartMode = ref<'top15' | 'custom'>('top15');
const selectedCompareRegions = ref<string[]>([]);

// map key → progress_pct for fast delta lookup
const compareMap = computed(
    () => new Map(compareData.value.map((r) => [r.key, r.progress_pct])),
);

// ── table search ─────────────────────────────────────────────────────────
const tableSearch = ref('');

// ── pagination ────────────────────────────────────────────────────────────
const pageSize = ref<10 | 20 | 50>(20);
const currentPage = ref(1);

// ── fetch ─────────────────────────────────────────────────────────────────
async function fetchData() {
    if (!filters.snapshot) {
        return;
    }

    loading.value = true;

    try {
        const params = new URLSearchParams({
            snapshot: filters.snapshot,
            role: filters.role,
            level: filters.level,
        });
        filters.filter_kec.forEach((c) => params.append('filter_kec[]', c));
        filters.filter_desa.forEach((c) => params.append('filter_desa[]', c));
        filters.filter_sls.forEach((c) => params.append('filter_sls[]', c));

        const res = await fetch(`/api/data?${params}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await res.json();
        metrics.value = data.metrics;
        statusTotals.value = data.status_totals;
        breakdown.value = data.breakdown;
        trend.value = data.trend;
        filterOptions.value = data.filter_options;
        currentPage.value = 1;
        tableSearch.value = '';
    } finally {
        loading.value = false;
    }
}

watch(filters, fetchData, { deep: true });
onMounted(fetchData);

async function fetchCompare() {
    if (!compareSnapshot.value || !filters.snapshot) {
        return;
    }

    compareLoading.value = true;

    try {
        const params = new URLSearchParams({
            snapshot: compareSnapshot.value,
            role: filters.role,
            level: filters.level,
        });
        filters.filter_kec.forEach((c) => params.append('filter_kec[]', c));
        filters.filter_desa.forEach((c) => params.append('filter_desa[]', c));
        filters.filter_sls.forEach((c) => params.append('filter_sls[]', c));
        const res = await fetch(`/api/data?${params}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await res.json();
        compareData.value = data.breakdown;
    } finally {
        compareLoading.value = false;
    }
}

function toggleCompare() {
    compareMode.value = !compareMode.value;

    if (compareMode.value) {
        if (!compareSnapshot.value && otherSnapshots.value.length) {
            compareSnapshot.value = otherSnapshots.value[0];
        }

        if (compareSnapshot.value) {
            fetchCompare();
        }
    } else {
        compareData.value = [];
        compareChartMode.value = 'top15';
        selectedCompareRegions.value = [];
    }
}

watch(compareSnapshot, () => {
    if (compareMode.value) {
        fetchCompare();
    }
});
watch(
    filters,
    () => {
        if (compareMode.value && compareSnapshot.value) {
            fetchCompare();
        }
    },
    { deep: true },
);

// ── level switch ──────────────────────────────────────────────────────────
function setLevel(l: Level) {
    filters.level = l;
    filters.filter_kec = [];
    filters.filter_desa = [];
    filters.filter_sls = [];
}

// cascade: kec change → reset desa+sls; desa change → reset sls
watch(
    () => filters.filter_kec,
    () => {
        filters.filter_desa = [];
        filters.filter_sls = [];
    },
);
watch(
    () => filters.filter_desa,
    () => {
        filters.filter_sls = [];
    },
);
function clearAllFilters() {
    filters.filter_kec = [];
    filters.filter_desa = [];
    filters.filter_sls = [];
}

const totalActiveFilters = computed(
    () =>
        filters.filter_kec.length +
        filters.filter_desa.length +
        filters.filter_sls.length,
);

const selectedFilterChips = computed(() => {
    const options = [
        ...(filterOptions.value?.kec ?? []),
        ...(filterOptions.value?.desa ?? []),
        ...(filterOptions.value?.sls ?? []),
    ];
    const selected = new Set([
        ...filters.filter_kec,
        ...filters.filter_desa,
        ...filters.filter_sls,
    ]);

    return options.filter((option) => selected.has(option.code));
});

const filterRegionDescription = computed(() =>
    totalActiveFilters.value
        ? `${totalActiveFilters.value} wilayah membatasi data dashboard`
        : 'Batasi data berdasarkan kecamatan, desa, atau SLS',
);

// ── filter option groups for PrimeVue MultiSelect ────────────────────────
const desaGroups = computed(() => {
    const opts = filterOptions.value?.desa ?? [];
    const groups: Record<string, FilterOption[]> = {};

    for (const opt of opts) {
        const key = opt.kec ?? '—';

        if (!groups[key]) {
            groups[key] = [];
        }

        groups[key].push(opt);
    }

    return Object.entries(groups).map(([label, items]) => ({ label, items }));
});

const slsGroups = computed(() => {
    const opts = filterOptions.value?.sls ?? [];
    const groups: Record<string, FilterOption[]> = {};

    for (const opt of opts) {
        const key = opt.desa ?? '—';

        if (!groups[key]) {
            groups[key] = [];
        }

        groups[key].push(opt);
    }

    return Object.entries(groups).map(([label, items]) => ({ label, items }));
});

const showKecFilter = computed(
    () => (filterOptions.value?.kec.length ?? 0) > 0,
);
const showDesaFilter = computed(
    () => (filterOptions.value?.desa?.length ?? 0) > 0,
);
const showSlsFilter = computed(
    () => (filterOptions.value?.sls?.length ?? 0) > 0,
);

// ── filter panel ──────────────────────────────────────────────────────────
const filterPanelOpen = ref(false);

// ── sort ──────────────────────────────────────────────────────────────────
const sortCol = ref<string>('label');
const sortDir = ref<'asc' | 'desc'>('asc');

function toggleSort(col: string) {
    if (sortCol.value === col) {
        sortDir.value = sortDir.value === 'desc' ? 'asc' : 'desc';
    } else {
        sortCol.value = col;
        sortDir.value = 'desc';
    }
}

const searchedBreakdown = computed(() => {
    const q = tableSearch.value.trim().toLowerCase();

    if (!q) {
        return breakdown.value;
    }

    return breakdown.value.filter(
        (row) =>
            row.label.toLowerCase().includes(q) ||
            (row.nmkec ?? '').toLowerCase().includes(q) ||
            (row.nmdesa ?? '').toLowerCase().includes(q),
    );
});

const sortedBreakdown = computed(() => {
    const copy = [...searchedBreakdown.value];
    copy.sort((a, b) => {
        const va = (
            STATUS_COLS.includes(sortCol.value)
                ? (a.statuses[sortCol.value] ?? 0)
                : (a as any)[sortCol.value]
        ) as number;
        const vb = (
            STATUS_COLS.includes(sortCol.value)
                ? (b.statuses[sortCol.value] ?? 0)
                : (b as any)[sortCol.value]
        ) as number;

        if (typeof va === 'string') {
            return sortDir.value === 'desc'
                ? (vb as any).localeCompare(va)
                : (va as any).localeCompare(vb as any);
        }

        return sortDir.value === 'desc'
            ? (vb as number) - (va as number)
            : (va as number) - (vb as number);
    });

    return copy;
});

// ── pagination computed ───────────────────────────────────────────────────
const totalRows = computed(() => sortedBreakdown.value.length);
const totalPages = computed(() =>
    Math.max(1, Math.ceil(totalRows.value / pageSize.value)),
);
const paginatedRows = computed(() => {
    const s = (currentPage.value - 1) * pageSize.value;

    return sortedBreakdown.value.slice(s, s + pageSize.value);
});
const pageStart = computed(() =>
    Math.min((currentPage.value - 1) * pageSize.value + 1, totalRows.value),
);
const pageEnd = computed(() =>
    Math.min(currentPage.value * pageSize.value, totalRows.value),
);

function goPage(p: number) {
    currentPage.value = Math.max(1, Math.min(p, totalPages.value));
}

watch(pageSize, () => {
    currentPage.value = 1;
});
watch(tableSearch, () => {
    currentPage.value = 1;
});

// ── chart data ────────────────────────────────────────────────────────────
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

const STATUS_META: Record<
    string,
    { short: string; color: string; title: string }
> = {
    OPEN: { short: 'Open', color: '', title: 'OPEN' },
    DRAFT: { short: 'Draft', color: '#FFD45A', title: 'DRAFT' },
    'SUBMITTED BY Pencacah': {
        short: 'Sub.P',
        color: '#3b82f6',
        title: 'SUBMITTED BY Pencacah',
    },
    'APPROVED BY Pengawas': {
        short: 'App.P',
        color: '#22c55e',
        title: 'APPROVED BY Pengawas',
    },
    'REJECTED BY Pengawas': {
        short: 'Rej.P',
        color: '#FF5A5A',
        title: 'REJECTED BY Pengawas',
    },
    'EDITED BY Pengawas': {
        short: 'Edit.P',
        color: '#FFA95A',
        title: 'EDITED BY Pengawas',
    },
    'REVOKED BY Pengawas': {
        short: 'Rev.P',
        color: '#dc2626',
        title: 'REVOKED BY Pengawas',
    },
    'SUBMITTED RESPONDENT': {
        short: 'Sub.R',
        color: '#a78bfa',
        title: 'SUBMITTED RESPONDENT',
    },
    'COMPLETED BY Admin Kabupaten': {
        short: 'Com.A',
        color: '#14b8a6',
        title: 'COMPLETED BY Admin Kabupaten',
    },
    'EDITED BY Admin Kabupaten': {
        short: 'Edit.A',
        color: '#f97316',
        title: 'EDITED BY Admin Kabupaten',
    },
    'REJECTED BY Admin Kabupaten': {
        short: 'Rej.A',
        color: '#be123c',
        title: 'REJECTED BY Admin Kabupaten',
    },
    'REVOKED BY Admin Kabupaten': {
        short: 'Rev.A',
        color: '#7f1d1d',
        title: 'REVOKED BY Admin Kabupaten',
    },
};

// Only show status columns that have any non-zero data
const activeStatusCols = computed(() =>
    STATUS_COLS.filter((col) =>
        breakdown.value.some((r) => (r.statuses[col] ?? 0) > 0),
    ),
);

const isDark = useDark();
const chartBg = computed(() => (isDark.value ? '#18181b' : '#ffffff'));
const chartMode = computed(() =>
    isDark.value ? ('dark' as const) : ('light' as const),
);

// Fluid chart font sizes — scale with viewport width
// 10-14px range for xs, 11-15px for sm, 12-16px for md
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

const donutSeries = computed(() =>
    STATUS_COLS.map((c) => statusTotals.value[c] ?? 0),
);
const donutColors = computed(() =>
    STATUS_COLS.map((c) => {
        if (c === 'OPEN') {
            return isDark.value ? '#71717a' : '#a1a1aa';
        }

        return STATUS_META[c].color;
    }),
);
const donutLabels = STATUS_COLS.map((c) => STATUS_META[c].title);

// Dynamic font sizes based on estimated donut inner radius
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

const donutOptions = computed(() => ({
    chart: {
        type: 'donut' as const,
        background: chartBg.value,
        toolbar: { show: false },
    },
    theme: { mode: chartMode.value },
    labels: donutLabels,
    colors: donutColors.value,
    legend: { show: false },
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

const TOP_N = 15;
const barTopData = computed(() =>
    [...breakdown.value]
        .sort((a, b) => b.progress_pct - a.progress_pct)
        .slice(0, TOP_N),
);

const barYMax = computed(() => {
    const maxVal = Math.max(0, ...barTopData.value.map((r) => r.progress_pct));

    return Math.max(20, Math.ceil((maxVal + 5) / 10) * 10);
});
const barCategories = computed(() =>
    barTopData.value.map((r) => r.label.slice(0, 18)),
);
const barSeries = computed(() => [
    { name: 'Submit %', data: barTopData.value.map((r) => r.progress_pct) },
    { name: 'Approved %', data: barTopData.value.map((r) => r.approved_pct) },
]);

const barOptions = computed(() => ({
    chart: {
        type: 'bar' as const,
        background: chartBg.value,
        toolbar: { show: false },
    },
    theme: { mode: chartMode.value },
    plotOptions: {
        bar: {
            horizontal: false,
            columnWidth: '65%',
            borderRadius: 3,
            dataLabels: { position: 'top' },
        },
    },
    colors: ['#FFA95A', '#22c55e'],
    xaxis: {
        categories: barCategories.value,
        labels: {
            rotate: -45,
            style: { fontSize: cFontXs.value },
        },
    },
    yaxis: {
        max: barYMax.value,
        labels: {
            formatter: (v: number) => v + '%',
            style: { fontSize: cFontXs.value },
        },
    },
    tooltip: { y: { formatter: (v: number) => v.toFixed(1) + '%' } },
    dataLabels: {
        enabled: true,
        formatter: (val: number) => (val > 0 ? val.toFixed(1) + '%' : ''),
        style: {
            fontSize: cFontXs.value,
            fontWeight: 500,
            colors: [isDark.value ? '#e4e4e7' : '#3f3f46'],
        },
        offsetY: -20,
    },
    legend: { position: 'top' as const, fontSize: cFontMd.value },
    grid: { padding: { top: 16 } },
}));

// ── projection ────────────────────────────────────────────────────────────
interface ProjPoint {
    label: string;
    y: number;
}
const DEADLINE = new Date('2026-08-31T23:59:59+08:00');
const REAL_TREND_POINT_COUNT = 7;
const PROJECTION_POINT_COUNT = 3;
const realTrendPoints = computed(() =>
    trend.value.slice(-REAL_TREND_POINT_COUNT),
);

const projectionPoints = computed<ProjPoint[]>(() => {
    const pts = realTrendPoints.value;

    if (pts.length < 2) {
        return [];
    }

    const n = pts.length;
    const xs = pts.map((_, i) => i);
    const ys = pts.map((p) => p.progress_pct);
    const xMean = xs.reduce((a, b) => a + b, 0) / n;
    const yMean = ys.reduce((a, b) => a + b, 0) / n;
    const num = xs.reduce((s, x, i) => s + (x - xMean) * (ys[i] - yMean), 0);
    const den = xs.reduce((s, x) => s + (x - xMean) ** 2, 0);

    if (den === 0) {
        return [];
    }

    const slope = num / den;
    const intercept = yMean - slope * xMean;

    const snapInterval =
        n >= 2
            ? (new Date(pts[n - 1].snapshot_at).getTime() -
                  new Date(pts[0].snapshot_at).getTime()) /
              (n - 1)
            : 24 * 60 * 60 * 1000;
    const result: ProjPoint[] = [];
    const lastDate = new Date(pts[n - 1].snapshot_at);

    for (let s = 1; s <= PROJECTION_POINT_COUNT; s++) {
        const projY = Math.max(
            0,
            Math.min(100, slope * (n - 1 + s) + intercept),
        );
        const projDate = new Date(lastDate.getTime() + snapInterval * s);

        const label = `${projDate.getDate().toString().padStart(2, '0')}/${(projDate.getMonth() + 1).toString().padStart(2, '0')} ${projDate.getHours().toString().padStart(2, '0')}:${projDate.getMinutes().toString().padStart(2, '0')}`;
        result.push({ label, y: Math.round(projY * 10) / 10 });
    }

    return result;
});

const projectionEstDate = computed<string | null>(() => {
    const pts = realTrendPoints.value;

    if (pts.length < 2) {
        return null;
    }

    const n = pts.length;
    const xs = pts.map((_, i) => i);
    const ys = pts.map((p) => p.progress_pct);
    const xMean = xs.reduce((a, b) => a + b, 0) / n;
    const yMean = ys.reduce((a, b) => a + b, 0) / n;
    const num = xs.reduce((s, x, i) => s + (x - xMean) * (ys[i] - yMean), 0);
    const den = xs.reduce((s, x) => s + (x - xMean) ** 2, 0);

    if (den === 0) {
        return null;
    }

    const slope = num / den;
    const intercept = yMean - slope * xMean;

    if (slope <= 0) {
        return null;
    }

    const stepsToHundred = (100 - intercept) / slope - (n - 1);

    if (stepsToHundred <= 0) {
        return 'sudah selesai';
    }

    const snapInterval =
        n >= 2
            ? (new Date(pts[n - 1].snapshot_at).getTime() -
                  new Date(pts[0].snapshot_at).getTime()) /
              (n - 1)
            : 24 * 60 * 60 * 1000;
    const est = new Date(
        new Date(pts[n - 1].snapshot_at).getTime() +
            snapInterval * stepsToHundred,
    );

    if (est > DEADLINE) {
        return '⚠️ Diprediksi melewati batas 31 Agustus';
    }

    return est.toLocaleDateString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
});

// ── funnel data ───────────────────────────────────────────────────────────
const FUNNEL_ORDER = [
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
] as const;

const funnelRows = computed(() => {
    const total =
        Object.values(statusTotals.value).reduce((a, b) => a + b, 0) || 1;

    return FUNNEL_ORDER.map((key) => ({
        key,
        label: STATUS_META[key]?.short ?? key,
        title: STATUS_META[key]?.title ?? key,
        color:
            key === 'OPEN'
                ? isDark.value
                    ? '#71717a'
                    : '#a1a1aa'
                : (STATUS_META[key]?.color ?? '#888'),
        count: statusTotals.value[key] ?? 0,
        pct: Math.round(((statusTotals.value[key] ?? 0) / total) * 100),
    })).filter((r) => r.count > 0);
});

const trendSeries = computed(() => {
    const actual = realTrendPoints.value;
    const series: { name: string; data: (number | null)[] }[] = [
        { name: 'Submit %', data: actual.map((t) => t.progress_pct) },
        { name: 'Submitted %', data: actual.map((t) => t.submitted_pct) },
        { name: 'Approved %', data: actual.map((t) => t.approved_pct) },
    ];

    if (projectionPoints.value.length >= 1) {
        const nullPad: (number | null)[] = actual.map(() => null);
        const last = actual[actual.length - 1]?.progress_pct ?? null;
        series.push({
            name: 'Proyeksi',
            data: [
                ...nullPad.slice(0, -1),
                last,
                ...projectionPoints.value.map((p) => p.y),
            ],
        });
    }

    return series;
});
const trendCategories = computed(() => {
    const real = realTrendPoints.value.map((t) => {
        const d = new Date(t.snapshot_at);

        return `${d.getDate().toString().padStart(2, '0')}/${(d.getMonth() + 1).toString().padStart(2, '0')} ${d.getHours().toString().padStart(2, '0')}:${d.getMinutes().toString().padStart(2, '0')}`;
    });
    const proj = projectionPoints.value.map((p) => p.label);

    return [...real, ...proj];
});
const trendMax = computed(() => {
    const maxVal = Math.max(
        0,
        ...realTrendPoints.value.map((t) =>
            Math.max(t.progress_pct, t.submitted_pct, t.approved_pct),
        ),
        ...projectionPoints.value.map((point) => point.y),
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
    stroke: {
        curve: 'smooth' as const,
        width: [2.5, 2.5, 2.5, 2],
        dashArray: [0, 0, 0, 6],
    },
    colors: ['#FFA95A', '#FF8B5A', '#22c55e', '#a78bfa'],
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
    grid: {
        padding: { right: 24 },
        borderColor: isDark.value
            ? 'rgba(255,255,255,0.07)'
            : 'rgba(0,0,0,0.07)',
    },
}));

// ── compare chart ─────────────────────────────────────────────────────────
const compareBarData = computed(() => {
    if (!compareData.value.length || !breakdown.value.length) {
        return null;
    }

    let rows: BreakdownRow[];

    if (
        compareChartMode.value === 'custom' &&
        selectedCompareRegions.value.length
    ) {
        const keys = new Set(selectedCompareRegions.value);
        rows = breakdown.value.filter((r) => keys.has(r.key));
    } else {
        rows = [...breakdown.value]
            .sort((a, b) => b.progress_pct - a.progress_pct)
            .slice(0, TOP_N)
            .reverse();
    }

    return {
        categories: rows.map((r) => r.label.slice(0, 22)),
        snap1: rows.map((r) => r.progress_pct),
        snap2: rows.map((r) => compareMap.value.get(r.key) ?? 0),
    };
});

const compareBarSeries = computed(() =>
    compareBarData.value
        ? [
              {
                  name: fmtSnap(filters.snapshot),
                  data: compareBarData.value.snap1,
              },
              {
                  name: fmtSnap(compareSnapshot.value),
                  data: compareBarData.value.snap2,
              },
          ]
        : [],
);

const compareBarOptions = computed(() => ({
    chart: {
        type: 'bar' as const,
        background: chartBg.value,
        toolbar: { show: false },
    },
    theme: { mode: chartMode.value },
    plotOptions: {
        bar: { horizontal: true, barHeight: '45%', borderRadius: 2 },
    },
    colors: ['#FFA95A', '#FFD45A'],
    xaxis: {
        categories: compareBarData.value?.categories ?? [],
        max: 100,
        labels: {
            formatter: (v: number) => v + '%',
            style: { fontSize: cFontSm.value },
        },
    },
    yaxis: { labels: { style: { fontSize: cFontSm.value } } },
    tooltip: { y: { formatter: (v: number) => v.toFixed(1) + '%' } },
    dataLabels: { enabled: false },
    legend: { position: 'top' as const, fontSize: cFontMd.value },
}));

// ── helpers ───────────────────────────────────────────────────────────────
const LEVEL_LABELS: Record<Level, string> = {
    kec: 'Kecamatan',
    desa: 'Desa',
    sls: 'SLS',
    subsls: 'Sub-SLS',
    by_pengawas: 'Per Pengawas',
    by_pencacah: 'Per Pencacah',
};

const roleOptions = [
    { label: 'Pengawas', value: 'pengawas' as Role },
    { label: 'Pencacah', value: 'pencacah' as Role },
];

const levelOptions = (Object.entries(LEVEL_LABELS) as [Level, string][]).map(
    ([value, label]) => ({ label, value }),
);

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

const sortIcon = (col: string) =>
    sortCol.value !== col ? '' : sortDir.value === 'desc' ? ' ↓' : ' ↑';

const isPetugasLevel = computed(
    () => filters.level === 'by_pengawas' || filters.level === 'by_pencacah',
);

// ── edit nama petugas ─────────────────────────────────────────────────────
function xmlEscape(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function excelCell(value: string | number): string {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return `<Cell><Data ss:Type="Number">${value}</Data></Cell>`;
    }

    return `<Cell><Data ss:Type="String">${xmlEscape(String(value))}</Data></Cell>`;
}

function safeFilenamePart(value: string): string {
    return value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function exportBreakdownExcel(): void {
    const rows = sortedBreakdown.value;
    const hasContext = rows.some((row) => rowContext(row));
    const columns = [
        {
            label: LEVEL_LABELS[filters.level],
            value: (row: BreakdownRow) => row.label,
        },
        ...(hasContext
            ? [
                  {
                      label: 'Konteks',
                      value: (row: BreakdownRow) => rowContext(row),
                  },
              ]
            : []),
        ...(isPetugasLevel.value
            ? [
                  {
                      label: 'ID Petugas',
                      value: (row: BreakdownRow) => row.key,
                  },
              ]
            : []),
        { label: 'Total Assignment', value: (row: BreakdownRow) => row.total },
        { label: '% Submit', value: (row: BreakdownRow) => row.progress_pct },
        {
            label: 'Progres Lapangan',
            value: (row: BreakdownRow) => row.lapangan_total,
        },
        {
            label: '% Progres Lapangan',
            value: (row: BreakdownRow) => row.lapangan_pct,
        },
        { label: 'Approved %', value: (row: BreakdownRow) => row.approved_pct },
        ...activeStatusCols.value.map((status) => ({
            label: STATUS_META[status]?.title ?? status,
            value: (row: BreakdownRow) => row.statuses[status] ?? 0,
        })),
    ];

    const header = `<Row>${columns
        .map((column) => excelCell(column.label))
        .join('')}</Row>`;
    const body = rows
        .map(
            (row) =>
                `<Row>${columns
                    .map((column) => excelCell(column.value(row)))
                    .join('')}</Row>`,
        )
        .join('');
    const worksheetName = xmlEscape(LEVEL_LABELS[filters.level].slice(0, 31));
    const xml = `<?xml version="1.0"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <Worksheet ss:Name="${worksheetName}">
  <Table>${header}${body}</Table>
 </Worksheet>
</Workbook>`;
    const blob = new Blob([xml], {
        type: 'application/vnd.ms-excel;charset=utf-8',
    });
    const anchor = document.createElement('a');
    const url = URL.createObjectURL(blob);

    anchor.href = url;
    anchor.download = `dashboard-${safeFilenamePart(LEVEL_LABELS[filters.level])}-${safeFilenamePart(filters.role)}-${new Date().toISOString().slice(0, 10)}.xls`;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
}

const editNameRow = ref<BreakdownRow | null>(null);
const editNameValue = ref('');
const editNameSaving = ref(false);

function openEditName(row: BreakdownRow) {
    editNameRow.value = row;
    editNameValue.value = row.label;
}

async function saveEditName() {
    if (!editNameRow.value) {
        return;
    }

    editNameSaving.value = true;

    try {
        await fetch('/api/petugas-names', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
            body: JSON.stringify({
                username: editNameRow.value.key,
                display_name: editNameValue.value.trim(),
            }),
        });
        editNameRow.value.label = editNameValue.value.trim();
        editNameRow.value = null;
    } finally {
        editNameSaving.value = false;
    }
}

// ── petugas per wilayah modal ─────────────────────────────────────────────
const petugasModal = ref(false);
const petugasRow = ref<BreakdownRow | null>(null);
const petugasLevel = ref<'kec' | 'desa' | 'sls' | 'subsls'>('kec');
const petugasBreakdown = ref<BreakdownRow[]>([]);
const petugasLoading = ref(false);
const petugasSearch = ref('');

const petugasSearched = computed(() => {
    const q = petugasSearch.value.trim().toLowerCase();

    if (!q) {
        return petugasBreakdown.value;
    }

    return petugasBreakdown.value.filter((r) =>
        r.label.toLowerCase().includes(q),
    );
});

async function fetchPetugasBreakdown() {
    if (!petugasRow.value || !filters.snapshot) {
        return;
    }

    petugasLoading.value = true;

    try {
        const params = new URLSearchParams({
            snapshot: filters.snapshot,
            role: filters.role,
            level: petugasLevel.value,
            petugas_username: petugasRow.value.key,
        });
        const res = await fetch(`/api/data?${params}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await res.json();
        petugasBreakdown.value = data.breakdown;
    } finally {
        petugasLoading.value = false;
    }
}

async function openPetugasModal(row: BreakdownRow) {
    if (editNameRow.value?.key === row.key) {
        return;
    }

    petugasRow.value = row;
    petugasLevel.value = 'kec';
    petugasSearch.value = '';
    petugasModal.value = true;
    await fetchPetugasBreakdown();
}

watch(petugasLevel, () => {
    if (petugasModal.value) {
        fetchPetugasBreakdown();
    }
});

function rowContext(row: BreakdownRow): string {
    if (filters.level === 'desa' && row.nmkec) {
        return row.nmkec;
    }

    if (filters.level === 'sls' && row.nmdesa) {
        return `${row.nmkec ?? ''} / ${row.nmdesa}`;
    }

    if (filters.level === 'subsls' && row.nmsls) {
        return `${row.nmkec ?? ''} / ${row.nmdesa ?? ''} / ${row.nmsls}`;
    }

    if (isPetugasLevel.value && row.desa_count !== undefined) {
        return `${row.kec_count} Kec, ${row.desa_count} Desa`;
    }

    return '';
}
</script>

<template>
    <Head title="Dashboard FASIH" />

    <!-- ── empty state ─────────────────────────────────────────────────── -->
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
                Klik <strong>Import Database</strong> di sidebar kiri bawah,<br />
                lalu upload file
                <code class="rounded bg-muted px-1 text-xs">fasih.db</code>.
            </p>
        </div>
    </div>

    <!-- ── dashboard ──────────────────────────────────────────────────── -->
    <div v-else class="flex h-full flex-1 flex-col gap-3 overflow-x-auto p-4">
        <!-- Filter bar -->
        <div
            class="sticky top-0 z-30 rounded-xl border border-border bg-card/95 px-3 py-2 shadow-sm backdrop-blur"
        >
            <div class="flex flex-col gap-2.5 md:hidden">
                <div class="flex items-start gap-2">
                    <div class="min-w-0 flex-1">
                        <span
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                            >Snapshot</span
                        >
                        <Select
                            v-model="filters.snapshot"
                            :options="snapshots"
                            :optionLabel="fmtSnap"
                            class="h-8 w-full min-w-0 text-xs"
                            size="small"
                        />
                    </div>

                    <button
                        :class="[
                            'mt-[21px] shrink-0 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                            compareMode
                                ? 'border-amber-400 bg-amber-400/10 text-amber-600 dark:text-amber-400'
                                : 'border-input bg-background text-muted-foreground hover:bg-muted',
                        ]"
                        @click="toggleCompare"
                        :title="
                            compareMode
                                ? 'Matikan mode bandingkan'
                                : 'Bandingkan 2 snapshot'
                        "
                    >
                        Bandingkan
                    </button>
                </div>

                <Select
                    v-if="compareMode && otherSnapshots.length"
                    v-model="compareSnapshot"
                    :options="otherSnapshots"
                    :optionLabel="fmtSnap"
                    size="small"
                    class="h-8 w-full border-amber-400/60 text-xs"
                />
                <span
                    v-else-if="compareMode && !otherSnapshots.length"
                    class="text-xs text-muted-foreground"
                    >Hanya 1 snapshot tersedia</span
                >

                <div class="space-y-1.5">
                    <span
                        class="block text-xs font-medium text-muted-foreground"
                        >Peran Petugas</span
                    >
                    <SelectButton
                        v-model="filters.role"
                        :options="roleOptions"
                        optionLabel="label"
                        optionValue="value"
                        size="small"
                        class="w-full"
                    />
                </div>

                <div class="space-y-1.5">
                    <span
                        class="block text-xs font-medium text-muted-foreground"
                        >Mode Ringkasan</span
                    >
                    <div class="grid grid-cols-3 gap-1.5">
                        <button
                            v-for="tab in levelOptions"
                            :key="tab.value"
                            type="button"
                            :class="[
                                'flex min-h-10 items-center justify-center rounded-lg border px-2 py-2 text-center text-[11px] leading-tight font-medium transition-colors',
                                filters.level === tab.value
                                    ? 'border-primary bg-primary text-primary-foreground shadow-sm'
                                    : 'border-input bg-background text-foreground hover:bg-muted',
                            ]"
                            @click="setLevel(tab.value)"
                        >
                            <span class="break-words whitespace-normal">
                                {{ tab.label }}
                            </span>
                        </button>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div
                        v-if="totalActiveFilters"
                        class="flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-accent-ink"
                    >
                        {{ totalActiveFilters }} filter aktif
                        <button
                            class="ml-1 rounded-full hover:text-destructive focus:outline-none"
                            @click="clearAllFilters"
                            aria-label="Hapus semua filter"
                        >
                            &times;
                        </button>
                    </div>

                    <button
                        class="ml-auto flex items-center justify-center rounded-md border border-input bg-background p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                        :title="
                            isDark
                                ? 'Beralih ke mode terang'
                                : 'Beralih ke mode gelap'
                        "
                        :aria-pressed="isDark"
                        aria-label="Toggle tema"
                        @click="isDark = !isDark"
                    >
                        <Sun v-if="isDark" class="size-4" />
                        <Moon v-else class="size-4" />
                    </button>

                    <span
                        v-if="loading"
                        class="w-full animate-pulse text-xs text-muted-foreground"
                        >Memuat...</span
                    >
                </div>
            </div>

            <div class="hidden flex-wrap items-center gap-2 md:flex">
                <!-- Snapshot -->
                <div class="flex items-center gap-1.5">
                    <span class="text-xs font-medium text-muted-foreground"
                        >Snapshot</span
                    >
                    <Select
                        v-model="filters.snapshot"
                        :options="snapshots"
                        :optionLabel="fmtSnap"
                        class="h-7 text-xs"
                        size="small"
                    />
                </div>

                <!-- Compare toggle -->
                <button
                    :class="[
                        'flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors',
                        compareMode
                            ? 'border-amber-400 bg-amber-400/10 text-amber-600 dark:text-amber-400'
                            : 'border-input bg-background text-muted-foreground hover:bg-muted',
                    ]"
                    @click="toggleCompare"
                    :title="
                        compareMode
                            ? 'Matikan mode bandingkan'
                            : 'Bandingkan 2 snapshot'
                    "
                >
                    Bandingkan
                </button>
                <Select
                    v-if="compareMode && otherSnapshots.length"
                    v-model="compareSnapshot"
                    :options="otherSnapshots"
                    :optionLabel="fmtSnap"
                    size="small"
                    class="h-7 border-amber-400/60 text-xs"
                />
                <span
                    v-if="compareMode && !otherSnapshots.length"
                    class="text-xs text-muted-foreground"
                    >Hanya 1 snapshot tersedia</span
                >

                <div class="h-4 w-px bg-border" />

                <!-- Role toggle -->
                <SelectButton
                    v-model="filters.role"
                    :options="roleOptions"
                    optionLabel="label"
                    optionValue="value"
                    size="small"
                />

                <div class="h-4 w-px bg-border" />

                <!-- Level tabs -->
                <SelectButton
                    v-model="filters.level"
                    :options="levelOptions"
                    optionLabel="label"
                    optionValue="value"
                    size="small"
                    @change="setLevel(filters.level)"
                />

                <!-- Active filter badge -->
                <div
                    v-if="totalActiveFilters"
                    class="ml-auto flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-accent-ink"
                >
                    {{ totalActiveFilters }} filter aktif
                    <button
                        class="ml-1 rounded-full hover:text-destructive focus:outline-none"
                        @click="clearAllFilters"
                        aria-label="Hapus semua filter"
                    >
                        ✕
                    </button>
                </div>

                <!-- Theme toggle -->
                <button
                    :class="[
                        'flex items-center justify-center rounded-md border border-input bg-background p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus:ring-2 focus:ring-ring focus:outline-none',
                        totalActiveFilters ? '' : 'ml-auto',
                    ]"
                    :title="
                        isDark
                            ? 'Beralih ke mode terang'
                            : 'Beralih ke mode gelap'
                    "
                    :aria-pressed="isDark"
                    aria-label="Toggle tema"
                    @click="isDark = !isDark"
                >
                    <Sun v-if="isDark" class="size-4" />
                    <Moon v-else class="size-4" />
                </button>

                <span
                    v-if="loading"
                    class="animate-pulse text-xs text-muted-foreground"
                    >Memuat…</span
                >
            </div>
        </div>

        <!-- Filter Wilayah — stacked cascading panel -->
        <div
            v-if="filterOptions !== null"
            :class="[
                'overflow-hidden rounded-2xl border bg-card shadow-sm transition-all duration-200',
                totalActiveFilters
                    ? 'border-orange-400/60 bg-gradient-to-r from-orange-500/10 via-card to-card shadow-orange-500/10'
                    : 'border-orange-300/35 bg-gradient-to-r from-orange-500/5 via-card to-card hover:border-orange-400/50 hover:shadow-md',
            ]"
        >
            <!-- Toggle header -->
            <button
                class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition-colors hover:bg-orange-500/5"
                @click="filterPanelOpen = !filterPanelOpen"
                :aria-expanded="filterPanelOpen"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-orange-500/12 text-orange-600 ring-1 ring-orange-500/20 dark:text-orange-300"
                    >
                        <MapPin class="size-5" />
                    </span>
                    <span class="min-w-0">
                        <span
                            class="block text-sm font-bold tracking-wide text-foreground"
                        >
                            Filter Wilayah
                        </span>
                        <span
                            class="mt-0.5 block truncate text-xs font-normal text-muted-foreground"
                        >
                            {{ filterRegionDescription }}
                        </span>
                    </span>
                </div>
                <span class="flex shrink-0 items-center gap-2">
                    <span
                        v-if="totalActiveFilters"
                        class="rounded-full bg-orange-500 px-2.5 py-1 text-[10px] font-bold text-white shadow-sm"
                    >
                        {{ totalActiveFilters }} aktif
                    </span>
                    <span
                        class="flex size-8 items-center justify-center rounded-full border border-border bg-background/80 text-muted-foreground shadow-sm"
                    >
                        <ChevronDown
                            class="size-4 transition-transform duration-200"
                            :class="filterPanelOpen ? 'rotate-180' : ''"
                        />
                    </span>
                </span>
            </button>

            <div
                v-if="selectedFilterChips.length && !filterPanelOpen"
                class="flex flex-wrap items-center gap-1.5 px-4 pb-3 pl-[4.25rem]"
            >
                <span
                    v-for="chip in selectedFilterChips.slice(0, 3)"
                    :key="chip.code"
                    class="max-w-44 truncate rounded-full border border-orange-300/50 bg-orange-100/70 px-2.5 py-1 text-[10px] font-semibold text-orange-800 dark:bg-orange-500/10 dark:text-orange-200"
                >
                    {{ chip.label }}
                </span>
                <span
                    v-if="selectedFilterChips.length > 3"
                    class="rounded-full bg-muted px-2.5 py-1 text-[10px] font-semibold text-muted-foreground"
                >
                    +{{ selectedFilterChips.length - 3 }} lainnya
                </span>
            </div>

            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="-translate-y-2 opacity-0"
                enter-to-class="translate-y-0 opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="translate-y-0 opacity-100"
                leave-to-class="-translate-y-2 opacity-0"
            >
                <div
                    v-if="filterPanelOpen"
                    class="grid grid-cols-1 gap-3 border-t border-orange-300/25 bg-background/35 px-4 py-4 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <!-- PROVINSI (readonly) -->
                    <div v-if="filterOptions.prov.length">
                        <p
                            class="mb-1 text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            Provinsi
                        </p>
                        <div
                            class="flex w-full cursor-not-allowed items-center justify-between rounded-md border border-dashed border-border bg-muted/60 px-3 py-2 text-sm text-muted-foreground/70 opacity-80 select-none"
                        >
                            <span class="italic">{{
                                filterOptions.prov[0]?.label ?? '—'
                            }}</span>
                            <span class="text-[10px] text-muted-foreground/40"
                                >hanya baca</span
                            >
                        </div>
                    </div>

                    <!-- KABUPATEN/KOTA (readonly) -->
                    <div v-if="filterOptions.kab.length">
                        <p
                            class="mb-1 text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            Kabupaten/Kota
                        </p>
                        <div
                            class="flex w-full cursor-not-allowed items-center justify-between rounded-md border border-dashed border-border bg-muted/60 px-3 py-2 text-sm text-muted-foreground/70 opacity-80 select-none"
                        >
                            <span class="italic">{{
                                filterOptions.kab[0]?.label ?? '—'
                            }}</span>
                            <span class="text-[10px] text-muted-foreground/40"
                                >hanya baca</span
                            >
                        </div>
                    </div>

                    <!-- KECAMATAN -->
                    <div v-if="showKecFilter">
                        <p
                            class="mb-1 text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            Kecamatan
                        </p>
                        <MultiSelect
                            v-model="filters.filter_kec"
                            :options="filterOptions!.kec"
                            optionLabel="label"
                            optionValue="code"
                            filter
                            :showToggleAll="true"
                            placeholder="Pilih kecamatan"
                            :maxSelectedLabels="2"
                            selectedItemsLabel="{0} dipilih"
                            class="w-full text-sm"
                            display="chip"
                        />
                    </div>

                    <!-- DESA -->
                    <div v-if="showDesaFilter">
                        <p
                            class="mb-1 text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            Desa
                        </p>
                        <MultiSelect
                            v-model="filters.filter_desa"
                            :options="desaGroups"
                            optionGroupLabel="label"
                            optionGroupChildren="items"
                            optionLabel="label"
                            optionValue="code"
                            filter
                            :showToggleAll="true"
                            placeholder="Pilih desa"
                            :maxSelectedLabels="2"
                            selectedItemsLabel="{0} dipilih"
                            class="w-full text-sm"
                            display="chip"
                        />
                    </div>

                    <!-- SLS -->
                    <div v-if="showSlsFilter">
                        <p
                            class="mb-1 text-[10px] font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            SLS
                        </p>
                        <MultiSelect
                            v-model="filters.filter_sls"
                            :options="slsGroups"
                            optionGroupLabel="label"
                            optionGroupChildren="items"
                            optionLabel="label"
                            optionValue="code"
                            filter
                            :showToggleAll="true"
                            placeholder="Pilih SLS"
                            :maxSelectedLabels="2"
                            selectedItemsLabel="{0} dipilih"
                            class="w-full text-sm"
                            display="chip"
                        />
                    </div>

                    <!-- Reset button -->
                    <button
                        v-if="totalActiveFilters"
                        class="col-span-full rounded-lg border border-orange-300/40 bg-orange-50/60 py-2 text-sm font-medium text-orange-800 transition-colors hover:bg-orange-100 focus:outline-none dark:bg-orange-500/10 dark:text-orange-200"
                        @click="clearAllFilters"
                    >
                        Hapus semua filter wilayah
                    </button>
                </div>
            </Transition>
        </div>

        <!-- Metric cards -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
            <div
                v-for="card in [
                    {
                        label: 'Petugas',
                        value: metrics.total_petugas,
                        fmt: 'n',
                        color: 'text-foreground',
                        ring: '',
                        tooltip: '',
                    },
                    {
                        label: 'Kecamatan',
                        value: metrics.total_kec,
                        fmt: 'n',
                        color: 'text-foreground',
                        ring: '',
                        tooltip: '',
                    },
                    {
                        label: 'Desa',
                        value: metrics.total_desa,
                        fmt: 'n',
                        color: 'text-foreground',
                        ring: '',
                        tooltip: '',
                    },
                    {
                        label: 'Total Assignment',
                        value: metrics.total_assignment,
                        fmt: 'n',
                        color: 'text-foreground',
                        ring: '',
                        tooltip: '',
                    },
                    {
                        label: '% Submit',
                        value: metrics.progress_pct,
                        fmt: 'p',
                        color: '',
                        hex: '#FFA95A',
                        ring: 'border-[#FFA95A]/60 bg-[#FFA95A]/10 shadow-md shadow-orange-500/10 dark:bg-[#FFA95A]/12',
                        tooltip:
                            '% Submit = (Total − OPEN − DRAFT) ÷ Total × 100%. Status OPEN dan DRAFT belum diproses, tidak dihitung sebagai submit.',
                    },
                    {
                        label: 'Submitted',
                        value: metrics.submitted_pct,
                        fmt: 'p',
                        color: '',
                        hex: '#3b82f6',
                        ring: 'border-blue-500/40 bg-blue-500/8 dark:bg-blue-500/12',
                        tooltip: '',
                    },
                    {
                        label: 'Approved',
                        value: metrics.approved_pct,
                        fmt: 'p',
                        color: '',
                        hex: '#22c55e',
                        ring: 'border-green-500/30 bg-green-500/5 dark:bg-green-500/10',
                        tooltip: '',
                    },
                    {
                        label: 'Rejected',
                        value: metrics.rejected_pct,
                        fmt: 'p',
                        color: '',
                        hex: '#FF5A5A',
                        ring: 'border-[#FF5A5A]/40 bg-[#FF5A5A]/8 dark:bg-[#FF5A5A]/12',
                        tooltip: '',
                    },
                ]"
                :key="card.label"
                :class="[
                    'rounded-xl border px-4 py-3',
                    card.ring
                        ? card.ring
                        : 'border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border',
                ]"
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
                        'mt-1 text-2xl font-bold tabular-nums',
                        card.color,
                    ]"
                    :style="
                        (card as any).hex ? { color: (card as any).hex } : {}
                    "
                >
                    {{
                        card.fmt === 'p'
                            ? card.value.toFixed(1) + '%'
                            : card.value.toLocaleString('id-ID')
                    }}
                </p>
            </div>
        </div>

        <!-- Charts row -->
        <div class="grid gap-3 md:grid-cols-3">
            <!-- Donut -->
            <div
                class="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border"
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

                <!-- Funnel -->
                <div v-if="funnelRows.length" class="mt-3 space-y-1.5 px-1">
                    <p class="text-[10px] font-medium text-muted-foreground">
                        Distribusi Status
                    </p>
                    <div
                        v-for="row in funnelRows"
                        :key="row.key"
                        class="flex items-center gap-2"
                    >
                        <span
                            class="w-28 shrink-0 text-right text-[10px] leading-tight text-muted-foreground"
                            :title="row.title"
                            >{{ row.title }}</span
                        >
                        <div
                            class="h-3 flex-1 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full transition-all"
                                :style="{
                                    width: `${row.pct}%`,
                                    backgroundColor: row.color,
                                }"
                            />
                        </div>
                        <span
                            class="w-12 shrink-0 text-[10px] text-muted-foreground"
                            >{{ row.count.toLocaleString('id') }}</span
                        >
                    </div>
                </div>
            </div>

            <!-- Bar -->
            <div
                class="flex flex-col rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm md:col-span-2 dark:border-sidebar-border"
            >
                <h3 class="mb-3 shrink-0 text-sm font-semibold md:hidden">
                    Peringkat {{ LEVEL_LABELS[filters.level] }} — Submit &
                    Approved %
                </h3>
                <h3 class="mb-1 hidden shrink-0 text-sm font-semibold md:block">
                    Top {{ TOP_N }} {{ LEVEL_LABELS[filters.level] }} — Submit &
                    Approved %
                </h3>
                <MobileRankingBars
                    v-if="barTopData.length"
                    class="md:hidden"
                    :rows="barTopData"
                />
                <div
                    v-else
                    class="flex min-h-32 items-center justify-center text-sm text-muted-foreground md:hidden"
                >
                    Tidak ada data
                </div>
                <div class="hidden min-h-0 flex-1 md:block">
                    <VueApexCharts
                        v-if="barSeries[0]?.data.length"
                        type="bar"
                        height="100%"
                        :options="barOptions"
                        :series="barSeries"
                    />
                    <div
                        v-else
                        class="flex h-full items-center justify-center text-sm text-muted-foreground"
                    >
                        Tidak ada data
                    </div>
                </div>
            </div>
        </div>

        <!-- Trend -->
        <div
            v-if="trend.length >= 1"
            class="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border"
        >
            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold">Tren Submit Over Time</h3>
                <span
                    v-if="realTrendPoints.length >= REAL_TREND_POINT_COUNT"
                    class="rounded-full bg-violet-500/10 px-2.5 py-1 text-[10px] font-semibold text-violet-600 dark:text-violet-300"
                >
                    7 aktual + 3 proyeksi
                </span>
            </div>
            <VueApexCharts
                type="line"
                :height="trend.length === 1 ? 120 : 200"
                :options="trendOptions"
                :series="trendSeries"
            />
            <p
                v-if="trend.length === 1"
                class="mt-1 text-center text-xs text-muted-foreground"
            >
                Hanya 1 snapshot — tambah snapshot lebih untuk melihat tren
            </p>
            <p
                v-if="projectionEstDate"
                class="mt-1 text-xs text-muted-foreground"
            >
                <span class="font-medium text-violet-500 dark:text-violet-400"
                    >Proyeksi selesai:</span
                >
                <span
                    :class="
                        projectionEstDate.startsWith('⚠️')
                            ? 'font-semibold text-red-500 dark:text-red-400'
                            : ''
                    "
                    >{{ projectionEstDate }}</span
                >
                <span
                    v-if="!projectionEstDate.startsWith('⚠️')"
                    class="text-[10px] text-muted-foreground/60"
                    >(berdasarkan tren linear)</span
                >
            </p>
        </div>

        <!-- Compare: Grouped bar -->
        <div
            v-if="compareMode && compareBarData"
            class="rounded-xl border border-amber-400/40 bg-card p-4 dark:border-amber-400/30"
        >
            <div class="mb-2 flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-semibold">
                    Perbandingan Submit —
                    {{ LEVEL_LABELS[filters.level] }}
                </h3>
                <!-- Top15 / Custom toggle -->
                <div
                    class="flex overflow-hidden rounded-md border border-amber-400/60 text-xs"
                >
                    <button
                        :class="[
                            'px-2.5 py-0.5 font-medium transition-colors',
                            compareChartMode === 'top15'
                                ? 'bg-amber-400/20 text-amber-700 dark:text-amber-300'
                                : 'bg-background text-muted-foreground hover:bg-muted',
                        ]"
                        @click="compareChartMode = 'top15'"
                    >
                        Top {{ TOP_N }}
                    </button>
                    <button
                        :class="[
                            'px-2.5 py-0.5 font-medium transition-colors',
                            compareChartMode === 'custom'
                                ? 'bg-amber-400/20 text-amber-700 dark:text-amber-300'
                                : 'bg-background text-muted-foreground hover:bg-muted',
                        ]"
                        @click="compareChartMode = 'custom'"
                    >
                        Pilih Wilayah
                    </button>
                </div>
                <span
                    v-if="compareLoading"
                    class="ml-auto animate-pulse text-xs text-muted-foreground"
                    >Memuat…</span
                >
            </div>
            <!-- Custom region picker -->
            <div
                v-if="compareChartMode === 'custom'"
                class="mb-3 max-h-32 overflow-y-auto rounded-lg border border-amber-400/30 bg-amber-50/30 p-2 dark:bg-amber-950/10"
            >
                <p class="mb-1.5 text-xs text-muted-foreground">
                    Pilih wilayah untuk dibandingkan:
                </p>
                <div class="flex flex-wrap gap-1.5">
                    <label
                        v-for="row in breakdown"
                        :key="row.key"
                        :class="[
                            'flex cursor-pointer items-center gap-1 rounded-md border px-2 py-0.5 text-xs transition-colors',
                            selectedCompareRegions.includes(row.key)
                                ? 'border-amber-400 bg-amber-400/10 text-amber-700 dark:text-amber-300'
                                : 'border-border bg-background text-foreground hover:border-amber-400/50',
                        ]"
                    >
                        <input
                            type="checkbox"
                            class="sr-only"
                            :checked="selectedCompareRegions.includes(row.key)"
                            @change="
                                selectedCompareRegions.includes(row.key)
                                    ? selectedCompareRegions.splice(
                                          selectedCompareRegions.indexOf(
                                              row.key,
                                          ),
                                          1,
                                      )
                                    : selectedCompareRegions.push(row.key)
                            "
                        />
                        {{ row.label.slice(0, 18) }}
                    </label>
                </div>
            </div>
            <VueApexCharts
                type="bar"
                :height="
                    Math.max(
                        220,
                        (compareChartMode === 'custom' &&
                        selectedCompareRegions.length
                            ? selectedCompareRegions.length
                            : TOP_N) * 32,
                    )
                "
                :options="compareBarOptions"
                :series="compareBarSeries"
            />
        </div>
        <div
            v-else-if="compareMode && !compareBarData && !compareLoading"
            class="flex items-center justify-center rounded-xl border border-amber-400/40 bg-card p-6 text-sm text-muted-foreground"
        >
            Pilih snapshot kedua untuk memulai perbandingan
        </div>

        <!-- Breakdown table -->
        <div
            class="rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border"
        >
            <!-- Table header -->
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border"
            >
                <div>
                    <h3 class="text-sm font-semibold">
                        Rincian per {{ LEVEL_LABELS[filters.level] }}
                    </h3>
                    <p
                        v-if="totalRows"
                        class="mt-0.5 text-xs text-muted-foreground"
                    >
                        {{ pageStart }}–{{ pageEnd }} dari {{ totalRows }} area
                        <span v-if="tableSearch">
                            · {{ totalRows }} hasil pencarian</span
                        >
                        <span v-else-if="totalActiveFilters"> · difilter</span>
                    </p>
                </div>
                <!-- Search + Page size -->
                <div class="flex flex-wrap items-center gap-3">
                    <button
                        class="inline-flex h-7 items-center gap-1.5 rounded-md border border-emerald-500/40 bg-emerald-500/10 px-2.5 text-xs font-semibold text-emerald-700 transition-colors hover:bg-emerald-500/15 disabled:cursor-not-allowed disabled:opacity-50 dark:text-emerald-300"
                        :disabled="!sortedBreakdown.length"
                        type="button"
                        title="Export semua baris sesuai filter, pencarian, sorting, dan kolom tabel saat ini"
                        @click="exportBreakdownExcel"
                    >
                        <svg
                            class="size-3.5"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke-width="2"
                            stroke="currentColor"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"
                            />
                        </svg>
                        Export Excel
                    </button>
                    <div class="relative flex items-center">
                        <svg
                            class="pointer-events-none absolute left-2 size-3.5 text-muted-foreground"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke-width="2"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="m21 21-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0Z"
                            />
                        </svg>
                        <input
                            v-model="tableSearch"
                            type="search"
                            placeholder="Cari wilayah…"
                            class="h-7 w-44 rounded-md border border-input bg-background pr-2 pl-7 text-xs text-foreground focus:ring-1 focus:ring-ring focus:outline-none"
                            aria-label="Cari pada tabel rincian"
                        />
                        <button
                            v-if="tableSearch"
                            class="absolute right-1.5 rounded text-muted-foreground hover:text-foreground focus:outline-none"
                            @click="tableSearch = ''"
                            aria-label="Hapus pencarian"
                        >
                            <svg
                                class="size-3"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke-width="2.5"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M6 18 18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>
                    <div
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <span>Tampilkan</span>
                        <Select
                            v-model="pageSize"
                            :options="[10, 20, 50]"
                            size="small"
                            class="h-7 w-20 text-xs"
                            aria-label="Jumlah baris per halaman"
                        />
                        <span>baris</span>
                    </div>
                </div>
            </div>

            <!-- Scrollable table -->
            <div class="overflow-x-auto">
                <table class="w-full min-w-max text-sm" role="grid">
                    <thead>
                        <tr
                            class="border-b border-sidebar-border/70 bg-muted/40 text-left text-xs text-muted-foreground dark:border-sidebar-border"
                        >
                            <th
                                class="sticky left-0 z-10 cursor-pointer bg-muted/40 px-4 py-2 font-semibold dark:bg-zinc-900/80"
                                @click="toggleSort('label')"
                                scope="col"
                            >
                                {{ LEVEL_LABELS[filters.level]
                                }}{{ sortIcon('label') }}
                            </th>
                            <th
                                class="cursor-pointer px-3 py-2 text-right font-semibold"
                                @click="toggleSort('total')"
                                scope="col"
                            >
                                Total Assignment{{ sortIcon('total') }}
                            </th>
                            <th
                                class="cursor-pointer px-3 py-2 font-semibold"
                                @click="toggleSort('progress_pct')"
                                scope="col"
                            >
                                % Submit{{ sortIcon('progress_pct') }}
                            </th>
                            <th
                                class="cursor-pointer px-3 py-2 text-right font-semibold"
                                @click="toggleSort('lapangan_total')"
                                scope="col"
                            >
                                Progres Lapangan{{ sortIcon('lapangan_total') }}
                            </th>
                            <th
                                v-if="compareMode && compareData.length"
                                class="px-2 py-2 text-right font-semibold text-amber-600 dark:text-amber-400"
                                :title="
                                    'Selisih vs ' + fmtSnap(compareSnapshot)
                                "
                                scope="col"
                            >
                                Δ%
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
                                class="cursor-pointer px-2 py-2 text-right font-semibold"
                                :title="STATUS_META[col].title"
                                @click="toggleSort(col)"
                                scope="col"
                            >
                                {{ STATUS_META[col].short }}{{ sortIcon(col) }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in paginatedRows"
                            :key="row.key"
                            :class="[
                                'border-b border-sidebar-border/30 transition-colors hover:bg-muted/30 dark:border-sidebar-border/20',
                                isPetugasLevel && editNameRow?.key !== row.key
                                    ? 'cursor-pointer'
                                    : '',
                            ]"
                            @click="
                                isPetugasLevel && editNameRow?.key !== row.key
                                    ? openPetugasModal(row)
                                    : null
                            "
                        >
                            <!-- Label -->
                            <td
                                class="sticky left-0 z-10 bg-card px-4 py-2.5 dark:bg-zinc-950"
                            >
                                <!-- Inline edit form (petugas level) -->
                                <div
                                    v-if="
                                        isPetugasLevel &&
                                        editNameRow?.key === row.key
                                    "
                                    class="flex items-center gap-1.5"
                                >
                                    <input
                                        v-model="editNameValue"
                                        type="text"
                                        class="h-7 w-40 rounded border border-input bg-background px-2 text-sm focus:ring-1 focus:ring-ring focus:outline-none"
                                        @keydown.enter="saveEditName"
                                        @keydown.escape="editNameRow = null"
                                        autofocus
                                    />
                                    <button
                                        class="rounded bg-primary px-2 py-1 text-xs text-primary-foreground hover:bg-primary/80 disabled:opacity-50"
                                        :disabled="editNameSaving"
                                        @click="saveEditName"
                                    >
                                        Simpan
                                    </button>
                                    <button
                                        class="rounded border border-input px-2 py-1 text-xs hover:bg-muted"
                                        @click="editNameRow = null"
                                    >
                                        Batal
                                    </button>
                                </div>
                                <!-- Normal display -->
                                <template v-else>
                                    <div class="flex items-center gap-1.5">
                                        <p class="leading-tight font-medium">
                                            {{ row.label }}
                                        </p>
                                        <button
                                            v-if="isPetugasLevel"
                                            class="text-muted-foreground/50 hover:text-primary focus:outline-none"
                                            :title="'Edit nama: ' + row.key"
                                            @click.stop="openEditName(row)"
                                        >
                                            <Pencil class="size-3" />
                                        </button>
                                    </div>
                                    <p
                                        v-if="rowContext(row)"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ rowContext(row) }}
                                    </p>
                                    <p
                                        v-if="isPetugasLevel"
                                        class="font-mono text-xs text-muted-foreground/60"
                                    >
                                        {{ row.key }}
                                    </p>
                                </template>
                            </td>
                            <!-- Total -->
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
                                            class="h-full rounded-full bg-[#FFA95A] transition-all"
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
                            <td
                                class="px-3 py-2.5 text-right font-medium tabular-nums"
                            >
                                <div class="leading-tight">
                                    <p>
                                        {{
                                            row.lapangan_total.toLocaleString(
                                                'id-ID',
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="text-[11px] text-muted-foreground"
                                    >
                                        {{ row.lapangan_pct }}%
                                    </p>
                                </div>
                            </td>
                            <!-- Delta % (compare mode) -->
                            <td
                                v-if="compareMode && compareData.length"
                                class="px-2 py-2.5 text-right text-xs tabular-nums"
                            >
                                <span
                                    v-if="compareMap.has(row.key)"
                                    :class="[
                                        'font-medium',
                                        row.progress_pct -
                                            (compareMap.get(row.key) ?? 0) >
                                        0
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : row.progress_pct -
                                                    (compareMap.get(row.key) ??
                                                        0) <
                                                0
                                              ? 'text-red-500'
                                              : 'text-muted-foreground',
                                    ]"
                                >
                                    {{
                                        (
                                            row.progress_pct -
                                            (compareMap.get(row.key) ?? 0)
                                        ).toFixed(1)
                                    }}%
                                </span>
                                <span v-else class="text-muted-foreground/40"
                                    >—</span
                                >
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
                                            class="h-full rounded-full bg-[#22c55e] transition-all"
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
                                        STATUS_META[col].color
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
                        <tr v-if="!paginatedRows.length">
                            <td
                                :colspan="
                                    5 +
                                    (compareMode && compareData.length
                                        ? 1
                                        : 0) +
                                    activeStatusCols.length
                                "
                                class="px-4 py-8 text-center text-sm text-muted-foreground"
                            >
                                Tidak ada data untuk filter yang dipilih.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination footer -->
            <div
                v-if="totalPages > 1"
                class="flex items-center justify-between gap-2 border-t border-sidebar-border/70 px-4 py-2.5 text-xs dark:border-sidebar-border"
            >
                <button
                    :disabled="currentPage === 1"
                    class="rounded-md border border-input bg-background px-3 py-1 font-medium transition-colors hover:bg-muted focus:ring-2 focus:ring-ring focus:outline-none disabled:opacity-40"
                    @click="goPage(currentPage - 1)"
                    aria-label="Halaman sebelumnya"
                >
                    ← Prev
                </button>

                <div class="flex items-center gap-1">
                    <template v-for="p in totalPages" :key="p">
                        <button
                            v-if="
                                p === 1 ||
                                p === totalPages ||
                                Math.abs(p - currentPage) <= 1
                            "
                            :class="[
                                'min-w-7 rounded-md border px-2 py-1 font-medium transition-colors focus:ring-2 focus:ring-ring focus:outline-none',
                                p === currentPage
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-input bg-background hover:bg-muted',
                            ]"
                            @click="goPage(p)"
                            :aria-label="`Halaman ${p}`"
                            :aria-current="
                                p === currentPage ? 'page' : undefined
                            "
                        >
                            {{ p }}
                        </button>
                        <span
                            v-else-if="p === 2 && currentPage > 3"
                            class="px-1 text-muted-foreground"
                            >…</span
                        >
                        <span
                            v-else-if="
                                p === totalPages - 1 &&
                                currentPage < totalPages - 2
                            "
                            class="px-1 text-muted-foreground"
                            >…</span
                        >
                    </template>
                </div>

                <button
                    :disabled="currentPage === totalPages"
                    class="rounded-md border border-input bg-background px-3 py-1 font-medium transition-colors hover:bg-muted focus:ring-2 focus:ring-ring focus:outline-none disabled:opacity-40"
                    @click="goPage(currentPage + 1)"
                    aria-label="Halaman berikutnya"
                >
                    Next →
                </button>
            </div>
        </div>
    </div>

    <!-- ── Petugas per wilayah modal ───────────────────────────────────── -->
    <Teleport to="body">
        <div
            v-if="petugasModal && petugasRow"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="petugasModal = false"
        >
            <div
                class="flex max-h-[85vh] w-full max-w-3xl flex-col rounded-xl border border-sidebar-border/70 bg-card shadow-xl dark:border-sidebar-border"
            >
                <!-- Modal header -->
                <div
                    class="flex items-center justify-between border-b border-sidebar-border/70 px-5 py-4"
                >
                    <div>
                        <h2 class="text-base font-semibold">
                            {{ petugasRow.label }}
                        </h2>
                        <p
                            class="mt-0.5 font-mono text-xs text-muted-foreground"
                        >
                            {{ petugasRow.key }} ·
                            {{ petugasRow.total.toLocaleString('id-ID') }}
                            assignment ·
                            {{ petugasRow.progress_pct }}% submit
                        </p>
                    </div>
                    <button
                        class="rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground focus:outline-none"
                        @click="petugasModal = false"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <!-- Level tabs -->
                <div
                    class="flex gap-1 border-b border-sidebar-border/70 px-5 py-2"
                >
                    <button
                        v-for="(lbl, lvl) in {
                            kec: 'Kecamatan',
                            desa: 'Desa',
                            sls: 'SLS',
                            subsls: 'Sub-SLS',
                        }"
                        :key="lvl"
                        :class="[
                            'rounded-full px-3 py-0.5 text-xs font-medium transition-colors',
                            petugasLevel === lvl
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-muted text-muted-foreground hover:bg-muted/80',
                        ]"
                        @click="
                            petugasLevel = lvl as
                                | 'kec'
                                | 'desa'
                                | 'sls'
                                | 'subsls'
                        "
                    >
                        {{ lbl }}
                    </button>
                    <input
                        v-model="petugasSearch"
                        type="search"
                        placeholder="Cari wilayah…"
                        class="ml-auto h-6 rounded-md border border-input bg-background px-2 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                    />
                </div>

                <!-- Table -->
                <div class="overflow-y-auto">
                    <div
                        v-if="petugasLoading"
                        class="flex h-32 items-center justify-center text-sm text-muted-foreground"
                    >
                        <span class="animate-pulse">Memuat…</span>
                    </div>
                    <table v-else class="w-full min-w-max text-sm">
                        <thead>
                            <tr
                                class="border-b border-sidebar-border/70 bg-muted/40 text-left text-xs text-muted-foreground"
                            >
                                <th class="px-4 py-2 font-semibold">Wilayah</th>
                                <th class="px-3 py-2 text-right font-semibold">
                                    Assignment
                                </th>
                                <th class="px-3 py-2 font-semibold">
                                    % Submit
                                </th>
                                <th class="px-3 py-2 font-semibold">
                                    Approved
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="r in petugasSearched"
                                :key="r.key"
                                class="border-b border-sidebar-border/30 hover:bg-muted/20"
                            >
                                <td class="px-4 py-2.5 font-medium">
                                    {{ r.label }}
                                    <span
                                        v-if="r.nmsls"
                                        class="block text-xs text-muted-foreground"
                                    >
                                        {{ r.nmkec }} / {{ r.nmdesa }} /
                                        {{ r.nmsls }}
                                    </span>
                                    <span
                                        v-else-if="r.nmdesa"
                                        class="block text-xs text-muted-foreground"
                                    >
                                        {{ r.nmkec }} / {{ r.nmdesa }}
                                    </span>
                                    <span
                                        v-else-if="r.nmkec"
                                        class="block text-xs text-muted-foreground"
                                    >
                                        {{ r.nmkec }}
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums">
                                    {{ r.total.toLocaleString('id-ID') }}
                                </td>
                                <td class="min-w-36 px-3 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <div
                                            class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                                        >
                                            <div
                                                class="h-full rounded-full bg-emerald-500"
                                                :style="{
                                                    width:
                                                        pct(r.progress_pct) +
                                                        '%',
                                                }"
                                            />
                                        </div>
                                        <span
                                            class="w-12 text-right text-xs tabular-nums"
                                            >{{ r.progress_pct }}%</span
                                        >
                                    </div>
                                </td>
                                <td class="min-w-28 px-3 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <div
                                            class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                                        >
                                            <div
                                                class="h-full rounded-full bg-blue-500"
                                                :style="{
                                                    width:
                                                        pct(r.approved_pct) +
                                                        '%',
                                                }"
                                            />
                                        </div>
                                        <span
                                            class="w-12 text-right text-xs tabular-nums"
                                            >{{ r.approved_pct }}%</span
                                        >
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!petugasSearched.length">
                                <td
                                    colspan="4"
                                    class="px-4 py-6 text-center text-sm text-muted-foreground"
                                >
                                    Tidak ada data wilayah.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </Teleport>
</template>
