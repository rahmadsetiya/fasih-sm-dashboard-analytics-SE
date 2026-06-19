<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Sun, Moon, Pencil, X } from '@lucide/vue';
import { useDark, useWindowSize } from '@vueuse/core';
import { ref, reactive, watch, computed, onMounted } from 'vue';

import VueApexCharts from 'vue3-apexcharts';

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

        if (!filters.snapshot && val.length) {
            filters.snapshot = val[0];
        }
    },
    { deep: true },
);

const metrics = ref<Metrics>({
    total_petugas: 0,
    total_kec: 0,
    total_desa: 0,
    total_rt: 0,
    progress_pct: 0,
    approved_pct: 0,
    submitted_pct: 0,
});
const statusTotals = ref<Record<string, number>>({});
const breakdown = ref<BreakdownRow[]>([]);
const trend = ref<TrendPoint[]>([]);
const filterOptions = ref<FilterOptions | null>(null);
const filterSearchKec = ref('');
const filterSearchDesa = ref('');
const filterSearchSls = ref('');

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
    filterSearchKec.value = '';
    filterSearchDesa.value = '';
    filterSearchSls.value = '';
}

function toggleKec(code: string) {
    const idx = filters.filter_kec.indexOf(code);

    if (idx === -1) {
        filters.filter_kec.push(code);
    } else {
        filters.filter_kec.splice(idx, 1);
    }

    filters.filter_desa = [];
    filters.filter_sls = [];
}

function toggleDesa(code: string) {
    const idx = filters.filter_desa.indexOf(code);

    if (idx === -1) {
        filters.filter_desa.push(code);
    } else {
        filters.filter_desa.splice(idx, 1);
    }

    filters.filter_sls = [];
}

function toggleSls(code: string) {
    const idx = filters.filter_sls.indexOf(code);

    if (idx === -1) {
        filters.filter_sls.push(code);
    } else {
        filters.filter_sls.splice(idx, 1);
    }
}

function selectAllKec() {
    filters.filter_kec = filteredKecOptions.value.map((o) => o.code);
    filters.filter_desa = [];
    filters.filter_sls = [];
}
function selectAllDesa() {
    filters.filter_desa = filteredDesaOptions.value.map((o) => o.code);
    filters.filter_sls = [];
}
function selectAllSls() {
    filters.filter_sls = filteredSlsOptions.value.map((o) => o.code);
}
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

// ── filter option search & computed options ───────────────────────────────
const filteredKecOptions = computed(() => {
    const opts = filterOptions.value?.kec ?? [];
    const q = filterSearchKec.value.toLowerCase();

    return q
        ? opts.filter(
              (o) => o.label.toLowerCase().includes(q) || o.code.includes(q),
          )
        : opts;
});

const filteredDesaOptions = computed(() => {
    const opts = filterOptions.value?.desa ?? [];
    const q = filterSearchDesa.value.toLowerCase();

    return q
        ? opts.filter(
              (o) =>
                  o.label.toLowerCase().includes(q) ||
                  o.code.includes(q) ||
                  (o.kec ?? '').toLowerCase().includes(q),
          )
        : opts;
});

const filteredSlsOptions = computed(() => {
    const opts = filterOptions.value?.sls ?? [];
    const q = filterSearchSls.value.toLowerCase();

    return q
        ? opts.filter(
              (o) =>
                  o.label.toLowerCase().includes(q) ||
                  o.code.includes(q) ||
                  (o.desa ?? '').toLowerCase().includes(q),
          )
        : opts;
});

const groupedDesaOptions = computed(() => {
    const opts = filteredDesaOptions.value;

    if (!opts.length) {
        return null;
    }

    const groups: Record<string, FilterOption[]> = {};

    for (const opt of opts) {
        const key = opt.kec ?? '—';

        if (!groups[key]) {
            groups[key] = [];
        }

        groups[key].push(opt);
    }

    return groups;
});

const groupedSlsOptions = computed(() => {
    const opts = filteredSlsOptions.value;

    if (!opts.length) {
        return null;
    }

    const groups: Record<string, FilterOption[]> = {};

    for (const opt of opts) {
        const key = opt.desa ?? '—';

        if (!groups[key]) {
            groups[key] = [];
        }

        groups[key].push(opt);
    }

    return groups;
});

const showKecFilter = computed(
    () => (filterOptions.value?.kec.length ?? 0) > 0,
);
const showDesaFilter = computed(
    () => (filterOptions.value?.desa?.length ?? 0) > 0,
);

// ── filter dropdowns ──────────────────────────────────────────────────────
const openDropdown = ref<'kec' | 'desa' | 'sls' | null>(null);

function toggleDropdown(name: 'kec' | 'desa' | 'sls') {
    openDropdown.value = openDropdown.value === name ? null : name;
}
const showSlsFilter = computed(
    () => (filterOptions.value?.sls?.length ?? 0) > 0,
);

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
];

const STATUS_META: Record<
    string,
    { short: string; color: string; title: string }
> = {
    OPEN: { short: 'Open', color: '', title: 'Belum diisi' },
    DRAFT: { short: 'Draft', color: '#6b7280', title: 'Sedang diisi' },
    'SUBMITTED BY Pencacah': {
        short: 'Sub.P',
        color: '#2563eb',
        title: 'Diserahkan Pencacah',
    },
    'APPROVED BY Pengawas': {
        short: 'App.P',
        color: '#16a34a',
        title: 'Disetujui Pengawas',
    },
    'REJECTED BY Pengawas': {
        short: 'Rej.P',
        color: '#dc2626',
        title: 'Ditolak Pengawas',
    },
    'EDITED BY Pengawas': {
        short: 'Edit.P',
        color: '#d97706',
        title: 'Diedit Pengawas',
    },
    'REVOKED BY Pengawas': {
        short: 'Rev.P',
        color: '#be185d',
        title: 'Dicabut Pengawas',
    },
    'SUBMITTED RESPONDENT': {
        short: 'Sub.R',
        color: '#4f46e5',
        title: 'Submit Responden',
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
const donutColors = STATUS_COLS.map((c) => STATUS_META[c].color);
const donutLabels = STATUS_COLS.map((c) => STATUS_META[c].title);

const donutOptions = computed(() => ({
    chart: {
        type: 'donut' as const,
        background: chartBg.value,
        toolbar: { show: false },
    },
    theme: { mode: chartMode.value },
    labels: donutLabels,
    colors: donutColors,
    legend: { position: 'bottom' as const, fontSize: cFontSm.value },
    dataLabels: { enabled: false },
    plotOptions: {
        pie: {
            donut: {
                size: '62%',
                labels: {
                    show: true,
                    total: {
                        show: true,
                        label: 'Total Assignment',
                        fontSize: cFontMd.value,
                        formatter: () =>
                            metrics.value.total_rt.toLocaleString('id-ID'),
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
        .slice(0, TOP_N)
        .reverse(),
);
const barCategories = computed(() =>
    barTopData.value.map((r) => r.label.slice(0, 22)),
);
const barSeries = computed(() => [
    { name: 'Progress %', data: barTopData.value.map((r) => r.progress_pct) },
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
        bar: { horizontal: true, barHeight: '40%', borderRadius: 3 },
    },
    colors: ['#059669', '#1d4ed8'],
    xaxis: {
        categories: barCategories.value,
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
    colors: ['#2563eb', '#f59e0b'],
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
            class="flex flex-wrap items-center gap-2 rounded-xl border border-sidebar-border/70 bg-card px-3 py-2 dark:border-sidebar-border"
        >
            <!-- Snapshot -->
            <div class="flex items-center gap-1.5">
                <span class="text-xs font-medium text-muted-foreground"
                    >Snapshot</span
                >
                <select
                    v-model="filters.snapshot"
                    class="h-7 rounded-md border border-input bg-background px-2 text-xs text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                >
                    <option v-for="s in snapshots" :key="s" :value="s">
                        {{ fmtSnap(s) }}
                    </option>
                </select>
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
            <select
                v-if="compareMode && otherSnapshots.length"
                v-model="compareSnapshot"
                class="h-7 rounded-md border border-amber-400/60 bg-background px-2 text-xs text-foreground focus:ring-2 focus:ring-ring focus:outline-none"
            >
                <option v-for="s in otherSnapshots" :key="s" :value="s">
                    {{ fmtSnap(s) }}
                </option>
            </select>
            <span
                v-if="compareMode && !otherSnapshots.length"
                class="text-xs text-muted-foreground"
                >Hanya 1 snapshot tersedia</span
            >

            <div class="h-4 w-px bg-border" />

            <!-- Role toggle -->
            <div
                class="flex overflow-hidden rounded-md border border-input text-xs"
            >
                <button
                    :class="[
                        'px-3 py-1 font-medium transition-colors',
                        filters.role === 'pengawas'
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-background text-foreground hover:bg-muted',
                    ]"
                    @click="filters.role = 'pengawas'"
                >
                    Pengawas
                </button>
                <button
                    :class="[
                        'px-3 py-1 font-medium transition-colors',
                        filters.role === 'pencacah'
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-background text-foreground hover:bg-muted',
                    ]"
                    @click="filters.role = 'pencacah'"
                >
                    Pencacah
                </button>
            </div>

            <div class="h-4 w-px bg-border" />

            <!-- Level tabs -->
            <div class="flex flex-wrap gap-1">
                <button
                    v-for="(lbl, key) in LEVEL_LABELS"
                    :key="key"
                    :class="[
                        'rounded-full px-3 py-0.5 text-xs font-medium transition-colors',
                        filters.level === key
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted text-muted-foreground hover:bg-muted/80',
                    ]"
                    @click="setLevel(key as Level)"
                >
                    {{ lbl }}
                </button>
            </div>

            <!-- Active filter badge -->
            <div
                v-if="totalActiveFilters"
                class="ml-auto flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary"
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
                    isDark ? 'Beralih ke mode terang' : 'Beralih ke mode gelap'
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

        <!-- Region filter dropdowns -->
        <div
            v-if="filterOptions !== null"
            class="flex flex-wrap items-center gap-2 rounded-xl border border-sidebar-border/70 bg-card px-3 py-2 dark:border-sidebar-border"
        >
            <span class="text-xs font-medium text-muted-foreground"
                >Filter:</span
            >

            <!-- Kecamatan dropdown -->
            <div v-if="showKecFilter" class="relative">
                <button
                    :class="[
                        'flex h-7 items-center gap-1.5 rounded-md border px-2.5 text-xs font-medium transition-colors',
                        filters.filter_kec.length
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'border-input bg-background text-foreground hover:bg-muted',
                    ]"
                    @click="toggleDropdown('kec')"
                >
                    Kecamatan
                    <span
                        v-if="filters.filter_kec.length"
                        class="rounded-full bg-primary px-1.5 py-0.5 text-[10px] text-primary-foreground"
                        >{{ filters.filter_kec.length }}</span
                    >
                    <span v-else class="text-muted-foreground opacity-60"
                        >▾</span
                    >
                </button>
                <div
                    v-if="openDropdown === 'kec'"
                    class="absolute top-full left-0 z-20 mt-1 w-64 overflow-hidden rounded-lg border border-border bg-card shadow-lg"
                >
                    <div
                        class="flex items-center gap-1 border-b border-border px-2 py-1.5"
                    >
                        <input
                            v-model="filterSearchKec"
                            type="search"
                            placeholder="Cari kecamatan…"
                            autofocus
                            class="h-6 flex-1 bg-transparent text-xs outline-none placeholder:text-muted-foreground"
                        />
                        <button
                            class="shrink-0 text-xs text-primary hover:underline focus:outline-none"
                            @click="selectAllKec"
                        >
                            Semua
                        </button>
                        <button
                            class="shrink-0 text-xs text-muted-foreground hover:underline focus:outline-none"
                            @click="
                                filters.filter_kec = [];
                                filters.filter_desa = [];
                                filters.filter_sls = [];
                            "
                        >
                            Hapus
                        </button>
                    </div>
                    <div class="max-h-60 overflow-y-auto py-1">
                        <label
                            v-for="opt in filteredKecOptions"
                            :key="opt.code"
                            class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-xs hover:bg-muted"
                        >
                            <input
                                type="checkbox"
                                class="rounded accent-primary"
                                :checked="filters.filter_kec.includes(opt.code)"
                                @change="toggleKec(opt.code)"
                            />
                            <span class="flex-1 truncate">{{ opt.label }}</span>
                            <span
                                class="shrink-0 text-muted-foreground tabular-nums"
                                >{{ opt.total.toLocaleString('id-ID') }}</span
                            >
                        </label>
                        <p
                            v-if="!filteredKecOptions.length"
                            class="px-3 py-2 text-xs text-muted-foreground"
                        >
                            Tidak ada hasil
                        </p>
                    </div>
                </div>
            </div>

            <!-- Desa dropdown -->
            <div v-if="showDesaFilter" class="relative">
                <button
                    :class="[
                        'flex h-7 items-center gap-1.5 rounded-md border px-2.5 text-xs font-medium transition-colors',
                        filters.filter_desa.length
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'border-input bg-background text-foreground hover:bg-muted',
                    ]"
                    @click="toggleDropdown('desa')"
                >
                    Desa
                    <span
                        v-if="filters.filter_desa.length"
                        class="rounded-full bg-primary px-1.5 py-0.5 text-[10px] text-primary-foreground"
                        >{{ filters.filter_desa.length }}</span
                    >
                    <span v-else class="text-muted-foreground opacity-60"
                        >▾</span
                    >
                </button>
                <div
                    v-if="openDropdown === 'desa'"
                    class="absolute top-full left-0 z-20 mt-1 w-72 overflow-hidden rounded-lg border border-border bg-card shadow-lg"
                >
                    <div
                        class="flex items-center gap-1 border-b border-border px-2 py-1.5"
                    >
                        <input
                            v-model="filterSearchDesa"
                            type="search"
                            placeholder="Cari desa…"
                            autofocus
                            class="h-6 flex-1 bg-transparent text-xs outline-none placeholder:text-muted-foreground"
                        />
                        <button
                            class="shrink-0 text-xs text-primary hover:underline focus:outline-none"
                            @click="selectAllDesa"
                        >
                            Semua
                        </button>
                        <button
                            class="shrink-0 text-xs text-muted-foreground hover:underline focus:outline-none"
                            @click="
                                filters.filter_desa = [];
                                filters.filter_sls = [];
                            "
                        >
                            Hapus
                        </button>
                    </div>
                    <div class="max-h-60 overflow-y-auto py-1">
                        <template
                            v-for="(items, kecName) in groupedDesaOptions ?? {}"
                            :key="kecName"
                        >
                            <p
                                class="px-3 pt-2 pb-0.5 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                {{ kecName }}
                            </p>
                            <label
                                v-for="opt in items"
                                :key="opt.code"
                                class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-xs hover:bg-muted"
                            >
                                <input
                                    type="checkbox"
                                    class="rounded accent-primary"
                                    :checked="
                                        filters.filter_desa.includes(opt.code)
                                    "
                                    @change="toggleDesa(opt.code)"
                                />
                                <span class="flex-1 truncate">{{
                                    opt.label
                                }}</span>
                                <span
                                    class="shrink-0 text-muted-foreground tabular-nums"
                                    >{{
                                        opt.total.toLocaleString('id-ID')
                                    }}</span
                                >
                            </label>
                        </template>
                        <p
                            v-if="!filteredDesaOptions.length"
                            class="px-3 py-2 text-xs text-muted-foreground"
                        >
                            Tidak ada hasil
                        </p>
                    </div>
                </div>
            </div>

            <!-- SLS dropdown -->
            <div v-if="showSlsFilter" class="relative">
                <button
                    :class="[
                        'flex h-7 items-center gap-1.5 rounded-md border px-2.5 text-xs font-medium transition-colors',
                        filters.filter_sls.length
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'border-input bg-background text-foreground hover:bg-muted',
                    ]"
                    @click="toggleDropdown('sls')"
                >
                    SLS
                    <span
                        v-if="filters.filter_sls.length"
                        class="rounded-full bg-primary px-1.5 py-0.5 text-[10px] text-primary-foreground"
                        >{{ filters.filter_sls.length }}</span
                    >
                    <span v-else class="text-muted-foreground opacity-60"
                        >▾</span
                    >
                </button>
                <div
                    v-if="openDropdown === 'sls'"
                    class="absolute top-full left-0 z-20 mt-1 w-72 overflow-hidden rounded-lg border border-border bg-card shadow-lg"
                >
                    <div
                        class="flex items-center gap-1 border-b border-border px-2 py-1.5"
                    >
                        <input
                            v-model="filterSearchSls"
                            type="search"
                            placeholder="Cari SLS…"
                            autofocus
                            class="h-6 flex-1 bg-transparent text-xs outline-none placeholder:text-muted-foreground"
                        />
                        <button
                            class="shrink-0 text-xs text-primary hover:underline focus:outline-none"
                            @click="selectAllSls"
                        >
                            Semua
                        </button>
                        <button
                            class="shrink-0 text-xs text-muted-foreground hover:underline focus:outline-none"
                            @click="filters.filter_sls = []"
                        >
                            Hapus
                        </button>
                    </div>
                    <div class="max-h-60 overflow-y-auto py-1">
                        <template
                            v-for="(items, desaName) in groupedSlsOptions ?? {}"
                            :key="desaName"
                        >
                            <p
                                class="px-3 pt-2 pb-0.5 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                {{ desaName }}
                            </p>
                            <label
                                v-for="opt in items"
                                :key="opt.code"
                                class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-xs hover:bg-muted"
                            >
                                <input
                                    type="checkbox"
                                    class="rounded accent-primary"
                                    :checked="
                                        filters.filter_sls.includes(opt.code)
                                    "
                                    @change="toggleSls(opt.code)"
                                />
                                <span class="flex-1 truncate">{{
                                    opt.label
                                }}</span>
                                <span
                                    class="shrink-0 text-muted-foreground tabular-nums"
                                    >{{
                                        opt.total.toLocaleString('id-ID')
                                    }}</span
                                >
                            </label>
                        </template>
                        <p
                            v-if="!filteredSlsOptions.length"
                            class="px-3 py-2 text-xs text-muted-foreground"
                        >
                            Tidak ada hasil
                        </p>
                    </div>
                </div>
            </div>

            <!-- Clear all -->
            <button
                v-if="totalActiveFilters"
                class="flex h-7 items-center gap-1 rounded-md px-2 text-xs text-muted-foreground hover:text-destructive focus:outline-none"
                @click="clearAllFilters"
            >
                ✕ Hapus semua
            </button>
        </div>

        <!-- Backdrop to close dropdowns on outside click -->
        <Teleport to="body">
            <div
                v-if="openDropdown"
                class="fixed inset-0 z-10"
                @click="openDropdown = null"
            />
        </Teleport>

        <!-- Metric cards -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
            <div
                v-for="card in [
                    {
                        label: 'Petugas',
                        value: metrics.total_petugas,
                        fmt: 'n',
                        color: 'text-violet-600 dark:text-violet-400',
                    },
                    {
                        label: 'Kecamatan',
                        value: metrics.total_kec,
                        fmt: 'n',
                        color: 'text-blue-600 dark:text-blue-400',
                    },
                    {
                        label: 'Desa',
                        value: metrics.total_desa,
                        fmt: 'n',
                        color: 'text-cyan-600 dark:text-cyan-400',
                    },
                    {
                        label: 'Total Assignment',
                        value: metrics.total_rt,
                        fmt: 'n',
                        color: 'text-foreground',
                    },
                    {
                        label: 'Progress',
                        value: metrics.progress_pct,
                        fmt: 'p',
                        color: 'text-emerald-600 dark:text-emerald-400',
                    },
                    {
                        label: 'Submitted',
                        value: metrics.submitted_pct,
                        fmt: 'p',
                        color: 'text-violet-600 dark:text-violet-400',
                    },
                    {
                        label: 'Approved',
                        value: metrics.approved_pct,
                        fmt: 'p',
                        color: 'text-blue-600 dark:text-blue-400',
                    },
                ]"
                :key="card.label"
                class="rounded-xl border border-sidebar-border/70 bg-card px-4 py-3 dark:border-sidebar-border"
            >
                <p class="text-sm text-muted-foreground">{{ card.label }}</p>
                <p
                    :class="[
                        'mt-1 text-2xl font-bold tabular-nums',
                        card.color,
                    ]"
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

            <!-- Bar -->
            <div
                class="col-span-2 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
            >
                <h3 class="mb-1 text-sm font-semibold">
                    Top {{ TOP_N }} {{ LEVEL_LABELS[filters.level] }} — Progress
                    & Approved %
                </h3>
                <VueApexCharts
                    v-if="barSeries[0]?.data.length"
                    type="bar"
                    height="260"
                    :options="barOptions"
                    :series="barSeries"
                />
                <div
                    v-else
                    class="flex h-52 items-center justify-center text-sm text-muted-foreground"
                >
                    Tidak ada data
                </div>
            </div>
        </div>

        <!-- Trend -->
        <div
            v-if="trend.length >= 1"
            class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
        >
            <h3 class="mb-1 text-sm font-semibold">Tren Progress Over Time</h3>
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
        </div>

        <!-- Compare: Grouped bar -->
        <div
            v-if="compareMode && compareBarData"
            class="rounded-xl border border-amber-400/40 bg-card p-4 dark:border-amber-400/30"
        >
            <div class="mb-2 flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-semibold">
                    Perbandingan Progress —
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
            class="rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
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
                        <select
                            v-model="pageSize"
                            class="h-7 rounded-md border border-input bg-background px-2 text-xs text-foreground focus:ring-1 focus:ring-ring focus:outline-none"
                            aria-label="Jumlah baris per halaman"
                        >
                            <option :value="10">10</option>
                            <option :value="20">20</option>
                            <option :value="50">50</option>
                        </select>
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
                                Progress{{ sortIcon('progress_pct') }}
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
                                    4 +
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
                class="flex max-h-[85vh] w-full max-w-3xl flex-col rounded-xl border border-sidebar-border bg-card shadow-xl dark:border-sidebar-border"
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
                            {{ petugasRow.progress_pct }}% progress
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
                                    Progress
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
