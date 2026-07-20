<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarClock,
    CheckCircle2,
    Download,
    Search,
    Target,
    TrendingUp,
    X,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import MultiSelect from 'primevue/multiselect';
import Select from 'primevue/select';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import VueApexCharts from 'vue3-apexcharts';

const props = defineProps<{
    db_ready: boolean;
    default_deadline: string;
}>();

type Role = 'pencacah' | 'pengawas';
type ProjectionStatus = 'done' | 'on_track' | 'behind' | 'no_rate';
type RejectRisk = 'low' | 'watch' | 'high';
type SortField =
    | 'name'
    | 'total_assignment'
    | 'submitted_total'
    | 'rejected_total'
    | 'rejection_rate'
    | 'remaining_total'
    | 'required_daily_submit'
    | 'actual_daily_rate'
    | 'quality_adjusted_daily_rate'
    | 'estimated_finish_date'
    | 'quality_adjusted_finish_date'
    | 'reject_risk'
    | 'projection_status';

interface FilterOption {
    code: string;
    raw_code?: string;
    label: string;
    total: number;
    kdkec?: string;
    kddes?: string;
}

interface ProjectionSummary {
    snapshot: string | null;
    deadline: string;
    days_left: number;
    role: Role;
    total_officers: number;
    total_assignment: number;
    submitted_total: number;
    rejected_total: number;
    rejection_rate: number;
    remaining_total: number;
    required_daily_submit: number;
    quality_adjusted_daily_rate: number;
    counts_by_status: Record<ProjectionStatus, number>;
    counts_by_reject_risk: Record<RejectRisk, number>;
}

interface ProjectionRow {
    officer_key: string;
    name: string;
    role: Role;
    role_label: string;
    total_assignment: number;
    submitted_total: number;
    rejected_total: number;
    rejection_rate: number;
    remaining_total: number;
    open_total: number;
    draft_total: number;
    required_daily_submit: number;
    actual_daily_rate: number;
    quality_adjusted_daily_rate: number;
    estimated_finish_date: string | null;
    quality_adjusted_finish_date: string | null;
    reject_risk: RejectRisk;
    reject_risk_label: string;
    projection_status: ProjectionStatus;
    projection_status_label: string;
    first_snapshot_date: string;
    snapshot_date: string;
    days_observed: number;
    submit_delta: number;
    statuses: Record<string, number>;
}

interface ProjectionResponse {
    empty: boolean;
    message: string | null;
    summary: ProjectionSummary;
    rows: ProjectionRow[];
    history: {
        date: string;
        submitted_total: number;
        rejected_total: number;
    }[];
    filter_options: {
        snapshots: string[];
        kec: FilterOption[];
        desa: FilterOption[];
        sls: FilterOption[];
    };
    status_columns: string[];
}

interface DetailResponse {
    empty: boolean;
    message: string | null;
    officer?: {
        key: string;
        name: string;
        role: Role;
        role_label: string;
    };
    metrics?: ProjectionRow;
    status_totals?: Record<string, number>;
    daily_history?: {
        date: string;
        total_assignment: number;
        submitted_total: number;
        rejected_total: number;
        open_total: number;
        draft_total: number;
    }[];
    target_vs_actual?: {
        date: string;
        actual_submit: number;
        actual_reject: number;
        target_submit: number;
    }[];
    regions?: {
        idsubsls: string;
        label: string;
        kecamatan: string;
        desa: string;
        sls: string;
        total_assignment: number;
        submitted_total: number;
        rejected_total: number;
        open_total: number;
        draft_total: number;
    }[];
}

const filters = reactive({
    role: 'pencacah' as Role,
    deadline: props.default_deadline,
    snapshot: '',
    kdkec: [] as string[],
    kddes: [] as string[],
    kdsls: [] as string[],
    status: '' as '' | ProjectionStatus,
    search: '',
    sort: 'remaining_total',
    direction: 'desc' as 'asc' | 'desc',
});

const loading = ref(false);
const error = ref('');
const data = ref<ProjectionResponse | null>(null);
const detail = ref<DetailResponse | null>(null);
const detailLoading = ref(false);
const selectedOfficerKey = ref('');
const currentPage = ref(1);
const pageSize = ref(20);

const summary = computed(() => data.value?.summary ?? null);
const rows = computed(() => data.value?.rows ?? []);
const filterOptions = computed(() => data.value?.filter_options ?? null);
const statusColumns = computed(() => data.value?.status_columns ?? []);

const roleOptions = [
    { label: 'Pencacah / PPL', value: 'pencacah' },
    { label: 'Pengawas / PML', value: 'pengawas' },
];

const statusOptions = [
    { label: 'Semua status', value: '' },
    { label: 'Aman', value: 'on_track' },
    { label: 'Berisiko', value: 'behind' },
    { label: 'Belum Bergerak', value: 'no_rate' },
    { label: 'Selesai', value: 'done' },
];

const pageSizeOptions = [10, 20, 50, 100];
const tableColumns: {
    label: string;
    field: SortField;
    align: 'left' | 'right';
}[] = [
    { label: 'Petugas', field: 'name', align: 'left' },
    { label: 'Total', field: 'total_assignment', align: 'right' },
    { label: 'Submit', field: 'submitted_total', align: 'right' },
    { label: 'Reject', field: 'rejected_total', align: 'right' },
    { label: 'Reject %', field: 'rejection_rate', align: 'right' },
    { label: 'Sisa', field: 'remaining_total', align: 'right' },
    { label: 'Target/Hari', field: 'required_daily_submit', align: 'right' },
    { label: 'Laju/Hari', field: 'actual_daily_rate', align: 'right' },
    {
        label: 'Laju Efektif',
        field: 'quality_adjusted_daily_rate',
        align: 'right',
    },
    { label: 'Estimasi', field: 'estimated_finish_date', align: 'left' },
    {
        label: 'Estimasi Efektif',
        field: 'quality_adjusted_finish_date',
        align: 'left',
    },
    { label: 'Risiko Reject', field: 'reject_risk', align: 'left' },
    { label: 'Status', field: 'projection_status', align: 'left' },
];

const totalPages = computed(() =>
    Math.max(1, Math.ceil(rows.value.length / pageSize.value)),
);
const pageStart = computed(() => (currentPage.value - 1) * pageSize.value);
const pageEnd = computed(() =>
    Math.min(rows.value.length, pageStart.value + pageSize.value),
);
const displayStart = computed(() =>
    rows.value.length === 0 ? 0 : pageStart.value + 1,
);
const paginatedRows = computed(() =>
    rows.value.slice(pageStart.value, pageEnd.value),
);

const kddesOptions = computed(() => {
    const selected = new Set(filters.kdkec);
    const options = filterOptions.value?.desa ?? [];

    return selected.size === 0
        ? options
        : options.filter((item) => item.kdkec && selected.has(item.kdkec));
});

const kdslsOptions = computed(() => {
    const selectedKec = new Set(filters.kdkec);
    const selectedDesa = new Set(filters.kddes);
    const options = filterOptions.value?.sls ?? [];

    return options.filter((item) => {
        const kecMatch =
            selectedKec.size === 0 || selectedKec.has(item.kdkec ?? '');
        const parentDesa = `${item.kdkec ?? ''}-${item.kddes ?? ''}`;
        const desaMatch =
            selectedDesa.size === 0 || selectedDesa.has(parentDesa);

        return kecMatch && desaMatch;
    });
});

function formatNumber(value: number | null | undefined): string {
    return new Intl.NumberFormat('id-ID').format(value ?? 0);
}

function formatRate(value: number | null | undefined): string {
    return new Intl.NumberFormat('id-ID', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(value ?? 0);
}

function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}

function statusClass(status: ProjectionStatus): string {
    return (
        {
            done: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200',
            on_track:
                'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
            behind: 'border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-200',
            no_rate:
                'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200',
        } as Record<ProjectionStatus, string>
    )[status];
}

function rejectRiskClass(risk: RejectRisk): string {
    return (
        {
            low: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
            watch: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
            high: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200',
        } as Record<RejectRisk, string>
    )[risk];
}

function toggleSort(field: SortField): void {
    if (filters.sort === field) {
        filters.direction = filters.direction === 'asc' ? 'desc' : 'asc';
    } else {
        filters.sort = field;
        filters.direction = field === 'name' ? 'asc' : 'desc';
    }

    currentPage.value = 1;
}

function sortIndicator(field: SortField): string {
    if (filters.sort !== field) {
        return '↕';
    }

    return filters.direction === 'asc' ? '↑' : '↓';
}

function sortableHeaderClass(align: 'left' | 'right'): string {
    return [
        'inline-flex items-center gap-1 rounded-md px-1.5 py-1 font-semibold transition hover:bg-muted',
        align === 'right'
            ? 'justify-end text-right'
            : 'justify-start text-left',
    ].join(' ');
}

function params(includeSearch = true): URLSearchParams {
    const query = new URLSearchParams({
        role: filters.role,
        deadline: filters.deadline,
        sort: filters.sort,
        direction: filters.direction,
    });

    if (filters.snapshot) {
        query.set('snapshot', filters.snapshot);
    }

    if (filters.status) {
        query.set('status', filters.status);
    }

    if (includeSearch && filters.search.trim()) {
        query.set('search', filters.search.trim());
    }

    filters.kdkec.forEach((value) => query.append('kdkec[]', value));
    filters.kddes.forEach((value) => query.append('kddes[]', value));
    filters.kdsls.forEach((value) => query.append('kdsls[]', value));

    return query;
}

async function fetchData(): Promise<void> {
    loading.value = true;
    error.value = '';

    try {
        const res = await fetch(`/api/projections/officers?${params()}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!res.ok) {
            throw new Error('Data proyeksi gagal dimuat.');
        }

        const payload = (await res.json()) as ProjectionResponse;
        data.value = payload;

        if (!filters.snapshot && payload.summary.snapshot) {
            filters.snapshot = payload.summary.snapshot;
        }

        currentPage.value = 1;
    } catch (err) {
        error.value =
            err instanceof Error ? err.message : 'Data proyeksi gagal dimuat.';
    } finally {
        loading.value = false;
    }
}

async function openDetail(row: ProjectionRow): Promise<void> {
    selectedOfficerKey.value = row.officer_key;
    detail.value = null;
    detailLoading.value = true;

    try {
        const res = await fetch(
            `/api/projections/officers/${encodeURIComponent(row.officer_key)}?${params(false)}`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );

        if (!res.ok) {
            throw new Error('Detail petugas gagal dimuat.');
        }

        detail.value = (await res.json()) as DetailResponse;
    } finally {
        detailLoading.value = false;
    }
}

function closeDetail(): void {
    selectedOfficerKey.value = '';
    detail.value = null;
}

watch(
    () => filters.role,
    () => {
        filters.snapshot = '';
        filters.kdkec = [];
        filters.kddes = [];
        filters.kdsls = [];
        fetchData();
    },
);

watch(
    () => filters.kdkec,
    () => {
        filters.kddes = filters.kddes.filter((code) =>
            kddesOptions.value.some((item) => item.code === code),
        );
        filters.kdsls = filters.kdsls.filter((code) =>
            kdslsOptions.value.some((item) => item.code === code),
        );
    },
    { deep: true },
);

watch(
    () => filters.kddes,
    () => {
        filters.kdsls = filters.kdsls.filter((code) =>
            kdslsOptions.value.some((item) => item.code === code),
        );
    },
    { deep: true },
);

watch(
    () => [
        filters.deadline,
        filters.snapshot,
        filters.kdkec.join(','),
        filters.kddes.join(','),
        filters.kdsls.join(','),
        filters.status,
        filters.search,
        filters.sort,
        filters.direction,
    ],
    () => fetchData(),
);

watch(pageSize, () => {
    currentPage.value = 1;
});

watch(rows, () => {
    if (currentPage.value > totalPages.value) {
        currentPage.value = totalPages.value;
    }
});

onMounted(fetchData);

const distributionSeries = computed(() => {
    const counts = summary.value?.counts_by_status;

    return [
        counts?.on_track ?? 0,
        counts?.behind ?? 0,
        counts?.no_rate ?? 0,
        counts?.done ?? 0,
    ];
});

const distributionOptions = computed(() => ({
    chart: { type: 'donut' as const, toolbar: { show: false } },
    labels: ['Aman', 'Berisiko', 'Belum Bergerak', 'Selesai'],
    colors: ['#10b981', '#f97316', '#e11d48', '#0284c7'],
    legend: { position: 'bottom' as const },
    dataLabels: { enabled: false },
    plotOptions: {
        pie: {
            donut: {
                size: '72%',
                labels: {
                    show: true,
                    total: { show: true, label: 'Petugas' },
                },
            },
        },
    },
}));

const rankingRows = computed(() => rows.value.slice(0, 12));
const rankingOptions = computed(() => ({
    chart: { type: 'bar' as const, toolbar: { show: false } },
    plotOptions: { bar: { borderRadius: 8, horizontal: true } },
    colors: ['#f97316'],
    xaxis: {
        categories: rankingRows.value.map((row) => row.name),
        labels: { style: { fontSize: '11px' } },
    },
    yaxis: { labels: { style: { fontSize: '11px' } } },
    dataLabels: { enabled: false },
}));
const rankingSeries = computed(() => [
    {
        name: 'Sisa',
        data: rankingRows.value.map((row) => row.remaining_total),
    },
]);

const detailTrendOptions = computed(() => ({
    chart: {
        type: 'line' as const,
        toolbar: { show: false },
        zoom: { enabled: false },
    },
    stroke: { width: 3, curve: 'smooth' as const },
    colors: ['#2563eb', '#f97316', '#e11d48'],
    xaxis: {
        categories:
            detail.value?.target_vs_actual?.map((point) => point.date) ?? [],
        labels: { rotate: -35 },
    },
    yaxis: { labels: { formatter: (value: number) => formatNumber(value) } },
}));
const detailTrendSeries = computed(() => [
    {
        name: 'Realisasi submit',
        data:
            detail.value?.target_vs_actual?.map(
                (point) => point.actual_submit,
            ) ?? [],
    },
    {
        name: 'Target kumulatif',
        data:
            detail.value?.target_vs_actual?.map(
                (point) => point.target_submit,
            ) ?? [],
    },
    {
        name: 'Reject kumulatif',
        data:
            detail.value?.target_vs_actual?.map(
                (point) => point.actual_reject,
            ) ?? [],
    },
]);

const summaryCards = computed<
    { label: string; value: number; className: string }[]
>(() => [
    {
        label: 'Total Assignment',
        value: summary.value?.total_assignment ?? 0,
        className: 'text-slate-900 dark:text-white',
    },
    {
        label: 'Sudah Submit',
        value: summary.value?.submitted_total ?? 0,
        className: 'text-emerald-600',
    },
    {
        label: 'Reject',
        value: summary.value?.rejected_total ?? 0,
        className: 'text-rose-600',
    },
    {
        label: 'Sisa',
        value: summary.value?.remaining_total ?? 0,
        className: 'text-orange-600',
    },
    {
        label: 'Petugas',
        value: summary.value?.total_officers ?? 0,
        className: 'text-sky-600',
    },
]);

const detailMetricCards = computed<
    { label: string; value: number; icon: LucideIcon }[]
>(() => {
    if (!detail.value?.metrics) {
        return [];
    }

    return [
        {
            label: 'Total',
            value: detail.value.metrics.total_assignment,
            icon: CalendarClock,
        },
        {
            label: 'Submit',
            value: detail.value.metrics.submitted_total,
            icon: CheckCircle2,
        },
        {
            label: 'Sisa',
            value: detail.value.metrics.remaining_total,
            icon: AlertTriangle,
        },
        {
            label: 'Reject',
            value: detail.value.metrics.rejected_total,
            icon: AlertTriangle,
        },
        {
            label: 'Target/Hari',
            value: detail.value.metrics.required_daily_submit,
            icon: TrendingUp,
        },
    ];
});

function exportExcel(): void {
    const headers = [
        'Petugas',
        'Role',
        'Total Assignment',
        'Sudah Submit',
        'Reject',
        'Reject %',
        'Sisa',
        'Open',
        'Draft',
        'Target Submit/Hari',
        'Laju Aktual/Hari',
        'Laju Efektif/Hari',
        'Estimasi Selesai',
        'Estimasi Efektif',
        'Risiko Reject',
        'Status Proyeksi',
    ];
    const body = rows.value.map((row) => [
        row.name,
        row.role_label,
        row.total_assignment,
        row.submitted_total,
        row.rejected_total,
        row.rejection_rate,
        row.remaining_total,
        row.open_total,
        row.draft_total,
        row.required_daily_submit,
        row.actual_daily_rate,
        row.quality_adjusted_daily_rate,
        row.estimated_finish_date ?? '-',
        row.quality_adjusted_finish_date ?? '-',
        row.reject_risk_label,
        row.projection_status_label,
    ]);
    const escapeXml = (value: string | number) =>
        String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    const rowsXml = [headers, ...body]
        .map(
            (row) =>
                `<Row>${row
                    .map(
                        (cell) =>
                            `<Cell><Data ss:Type="String">${escapeXml(cell)}</Data></Cell>`,
                    )
                    .join('')}</Row>`,
        )
        .join('');
    const xml = `<?xml version="1.0"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Worksheet ss:Name="Proyeksi Petugas"><Table>${rowsXml}</Table></Worksheet>
</Workbook>`;
    const blob = new Blob([xml], { type: 'application/vnd.ms-excel' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `proyeksi-petugas-${filters.role}-${filters.deadline}.xls`;
    link.click();
    URL.revokeObjectURL(link.href);
}
</script>

<template>
    <Head title="Proyeksi Petugas" />

    <main class="flex min-h-screen flex-col gap-3 p-4 text-foreground">
        <section class="mx-auto flex w-full max-w-7xl flex-col gap-3">
            <div
                class="rounded-xl border border-sidebar-border/70 bg-card px-4 py-3 shadow-sm dark:border-sidebar-border"
            >
                <div
                    class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between"
                >
                    <div class="space-y-2">
                        <div
                            class="inline-flex items-center gap-2 rounded-full border border-emerald-500/25 bg-emerald-500/10 px-3 py-1 text-xs font-semibold tracking-[0.14em] text-emerald-700 uppercase dark:text-emerald-300"
                        >
                            <Target class="size-3.5" />
                            Proyeksi Selesai Petugas
                        </div>
                        <div>
                            <h1
                                class="text-2xl font-bold tracking-tight md:text-3xl"
                            >
                                Target submit harian sampai deadline
                            </h1>
                            <p
                                class="mt-1 max-w-3xl text-sm leading-6 text-muted-foreground"
                            >
                                Pantau kebutuhan minimum submit per PPL/PML,
                                bandingkan dengan laju aktual, lalu buka detail
                                petugas untuk melihat tren dan wilayah tugas.
                            </p>
                        </div>
                    </div>
                    <div
                        class="rounded-xl border border-sidebar-border/70 bg-muted/35 px-4 py-3 md:min-w-72 dark:border-sidebar-border"
                    >
                        <p class="text-xs font-semibold text-muted-foreground">
                            Target kabupaten per hari
                        </p>
                        <div class="mt-2 flex items-end gap-2">
                            <span class="text-3xl font-bold">
                                {{
                                    formatNumber(summary?.required_daily_submit)
                                }}
                            </span>
                            <span class="pb-1 text-sm text-muted-foreground">
                                submit/hari
                            </span>
                        </div>
                        <p class="mt-2 text-sm text-muted-foreground">
                            Sisa
                            {{ formatNumber(summary?.remaining_total) }}
                            assignment dalam
                            {{ formatNumber(summary?.days_left) }} hari menuju
                            {{ formatDate(summary?.deadline) }}.
                        </p>
                        <p
                            class="mt-2 text-xs font-semibold text-rose-600 dark:text-rose-300"
                        >
                            Reject:
                            {{ formatNumber(summary?.rejected_total) }}
                            ({{ formatRate(summary?.rejection_rate) }}%)
                        </p>
                    </div>
                </div>
            </div>

            <div
                v-if="!props.db_ready"
                class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-800 dark:text-amber-100"
            >
                Database FASIH belum tersedia. Upload/import database dulu untuk
                melihat proyeksi petugas.
            </div>

            <section
                class="grid gap-3 rounded-xl border border-sidebar-border/70 bg-card p-3 shadow-sm md:grid-cols-6 dark:border-sidebar-border"
            >
                <Select
                    v-model="filters.role"
                    :options="roleOptions"
                    option-label="label"
                    option-value="value"
                    class="md:col-span-1"
                    placeholder="Role"
                />
                <input
                    v-model="filters.deadline"
                    type="date"
                    class="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
                <Select
                    v-model="filters.snapshot"
                    :options="filterOptions?.snapshots ?? []"
                    class="md:col-span-2"
                    placeholder="Snapshot terbaru"
                />
                <Select
                    v-model="filters.status"
                    :options="statusOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="Status"
                />
                <MultiSelect
                    v-model="filters.kdkec"
                    :options="filterOptions?.kec ?? []"
                    option-label="label"
                    option-value="code"
                    display="chip"
                    filter
                    placeholder="Kecamatan"
                    class="md:col-span-2"
                />
                <MultiSelect
                    v-model="filters.kddes"
                    :options="kddesOptions"
                    option-label="label"
                    option-value="code"
                    display="chip"
                    filter
                    placeholder="Desa"
                    class="md:col-span-2"
                />
                <MultiSelect
                    v-model="filters.kdsls"
                    :options="kdslsOptions"
                    option-label="label"
                    option-value="code"
                    display="chip"
                    filter
                    placeholder="SLS"
                    class="md:col-span-2"
                />
                <label
                    class="flex h-10 items-center gap-2 rounded-md border border-input bg-background px-3 py-2 md:col-span-5"
                >
                    <Search class="size-4 text-muted-foreground" />
                    <input
                        v-model="filters.search"
                        class="w-full bg-transparent text-sm outline-none"
                        placeholder="Cari nama petugas..."
                    />
                </label>
                <button
                    class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-emerald-500/40 bg-emerald-500/10 px-4 py-2 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-500/15 dark:text-emerald-300"
                    @click="exportExcel"
                >
                    <Download class="size-4" />
                    Export
                </button>
            </section>

            <div
                v-if="error"
                class="rounded-xl border border-rose-500/25 bg-rose-500/10 p-4 text-sm text-rose-700 dark:text-rose-200"
            >
                {{ error }}
            </div>

            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div
                    v-for="card in summaryCards"
                    :key="card.label"
                    class="rounded-xl border border-sidebar-border/70 bg-card px-3 py-2.5 shadow-sm dark:border-sidebar-border"
                >
                    <p class="text-xs font-semibold text-muted-foreground">
                        {{ card.label }}
                    </p>
                    <p class="mt-1 text-2xl font-bold" :class="card.className">
                        {{ formatNumber(card.value) }}
                    </p>
                </div>
            </section>

            <section class="grid gap-3 lg:grid-cols-[0.75fr_1.25fr]">
                <div
                    class="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border"
                >
                    <h2 class="text-sm font-semibold">
                        Distribusi Status Proyeksi
                    </h2>
                    <VueApexCharts
                        height="290"
                        type="donut"
                        :options="distributionOptions"
                        :series="distributionSeries"
                    />
                </div>
                <div
                    class="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border"
                >
                    <h2 class="text-sm font-semibold">
                        Ranking Sisa Assignment
                    </h2>
                    <VueApexCharts
                        height="290"
                        type="bar"
                        :options="rankingOptions"
                        :series="rankingSeries"
                    />
                </div>
            </section>

            <section
                class="grid gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm md:grid-cols-2 xl:grid-cols-4 dark:border-sidebar-border"
            >
                <div class="space-y-2">
                    <div
                        class="inline-flex items-center gap-2 rounded-full border border-emerald-500/25 bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300"
                    >
                        <CheckCircle2 class="size-3.5" />
                        Dasar progress
                    </div>
                    <p class="text-sm text-muted-foreground">
                        <span class="font-semibold text-foreground"
                            >Submit</span
                        >
                        berarti pekerjaan sudah mulai bergerak. Yang dihitung
                        sebagai submit adalah semua status selain
                        <span class="font-semibold text-foreground">OPEN</span>
                        dan
                        <span class="font-semibold text-foreground">DRAFT</span
                        >, termasuk approve, reject, edited, revoked,
                        respondent, dan status dari Admin Kabupaten.
                    </p>
                </div>

                <div class="space-y-2">
                    <div
                        class="inline-flex items-center gap-2 rounded-full border border-amber-500/25 bg-amber-500/10 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:text-amber-300"
                    >
                        <TrendingUp class="size-3.5" />
                        Laju efektif
                    </div>
                    <p class="text-sm text-muted-foreground">
                        <span class="font-semibold text-foreground"
                            >Laju efektif</span
                        >
                        adalah kecepatan kerja setelah memperhitungkan reject.
                        Misalnya laju aktual 10 submit/hari dan reject 20%, maka
                        laju efektif dibaca sekitar 8 submit/hari.
                    </p>
                </div>

                <div class="space-y-2">
                    <div
                        class="inline-flex items-center gap-2 rounded-full border border-sky-500/25 bg-sky-500/10 px-2.5 py-1 text-xs font-semibold text-sky-700 dark:text-sky-300"
                    >
                        <CalendarClock class="size-3.5" />
                        Estimasi selesai
                    </div>
                    <p class="text-sm text-muted-foreground">
                        <span class="font-semibold text-foreground"
                            >Estimasi</span
                        >
                        adalah perkiraan tanggal selesai jika petugas bekerja
                        dengan kecepatan aktual seperti histori terakhir, tanpa
                        mengurangi karena reject.
                    </p>
                </div>

                <div class="space-y-2">
                    <div
                        class="inline-flex items-center gap-2 rounded-full border border-rose-500/25 bg-rose-500/10 px-2.5 py-1 text-xs font-semibold text-rose-700 dark:text-rose-300"
                    >
                        <AlertTriangle class="size-3.5" />
                        Estimasi efektif
                    </div>
                    <p class="text-sm text-muted-foreground">
                        <span class="font-semibold text-foreground"
                            >Estimasi efektif</span
                        >
                        adalah perkiraan yang lebih hati-hati karena memakai
                        laju efektif. Kalau reject tinggi, tanggal ini bisa
                        lebih mundur. Risiko reject:
                        <span class="font-semibold text-foreground"
                            >&lt;5%</span
                        >
                        rendah,
                        <span class="font-semibold text-foreground"
                            >5%-10%</span
                        >
                        perlu pantau,
                        <span class="font-semibold text-foreground">>=10%</span>
                        tinggi.
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Reject dihitung dari
                        <span class="font-semibold text-foreground"
                            >REJECTED BY Pengawas</span
                        >
                        dan
                        <span class="font-semibold text-foreground"
                            >REJECTED BY Admin Kabupaten</span
                        >.
                    </p>
                </div>
            </section>

            <section
                class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border"
            >
                <div
                    class="flex flex-col gap-2 border-b border-sidebar-border/70 px-4 py-3 md:flex-row md:items-center md:justify-between dark:border-sidebar-border"
                >
                    <div>
                        <h2 class="text-base font-semibold">
                            Target Submit Harian Per Petugas
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Klik header kolom untuk mengurutkan. Klik baris
                            untuk membuka tampilan rinci.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="text-muted-foreground">
                            {{ formatNumber(displayStart) }}-{{
                                formatNumber(pageEnd)
                            }}
                            dari {{ formatNumber(rows.length) }}
                        </span>
                        <select
                            v-model.number="pageSize"
                            class="h-8 rounded-md border border-input bg-background px-2 text-xs font-semibold"
                            aria-label="Jumlah baris per halaman"
                        >
                            <option
                                v-for="option in pageSizeOptions"
                                :key="option"
                                :value="option"
                            >
                                {{ option }}/halaman
                            </option>
                        </select>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead
                            class="bg-muted/45 text-xs text-muted-foreground uppercase"
                        >
                            <tr>
                                <th
                                    v-for="column in tableColumns"
                                    :key="column.field"
                                    class="px-4 py-3"
                                    :class="
                                        column.align === 'right'
                                            ? 'text-right'
                                            : 'text-left'
                                    "
                                >
                                    <button
                                        type="button"
                                        :class="
                                            sortableHeaderClass(column.align)
                                        "
                                        @click="toggleSort(column.field)"
                                    >
                                        <span>{{ column.label }}</span>
                                        <span
                                            class="text-[10px]"
                                            :class="
                                                filters.sort === column.field
                                                    ? 'text-emerald-600'
                                                    : 'text-muted-foreground/70'
                                            "
                                        >
                                            {{ sortIndicator(column.field) }}
                                        </span>
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in paginatedRows"
                                :key="row.officer_key"
                                class="cursor-pointer border-t border-sidebar-border/60 transition hover:bg-muted/60"
                                @click="openDetail(row)"
                            >
                                <td class="px-4 py-3">
                                    <div class="font-bold">{{ row.name }}</div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ row.role_label }} ·
                                        {{ row.days_observed }} hari histori
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{ formatNumber(row.total_assignment) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{ formatNumber(row.submitted_total) }}
                                </td>
                                <td class="px-4 py-3 text-right text-rose-600">
                                    {{ formatNumber(row.rejected_total) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{ formatRate(row.rejection_rate) }}%
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-bold text-amber-600 dark:text-amber-300"
                                >
                                    {{ formatNumber(row.remaining_total) }}
                                </td>
                                <td class="px-4 py-3 text-right font-bold">
                                    {{
                                        formatNumber(row.required_daily_submit)
                                    }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{ formatRate(row.actual_daily_rate) }}
                                </td>
                                <td class="px-4 py-3 text-right font-semibold">
                                    {{
                                        formatRate(
                                            row.quality_adjusted_daily_rate,
                                        )
                                    }}
                                </td>
                                <td class="px-4 py-3">
                                    {{ formatDate(row.estimated_finish_date) }}
                                </td>
                                <td class="px-4 py-3">
                                    {{
                                        formatDate(
                                            row.quality_adjusted_finish_date,
                                        )
                                    }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full border px-2.5 py-1 text-xs font-bold"
                                        :class="
                                            rejectRiskClass(row.reject_risk)
                                        "
                                    >
                                        {{ row.reject_risk_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full border px-2.5 py-1 text-xs font-bold"
                                        :class="
                                            statusClass(row.projection_status)
                                        "
                                    >
                                        {{ row.projection_status_label }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!loading && rows.length === 0">
                                <td
                                    colspan="13"
                                    class="px-4 py-10 text-center text-muted-foreground"
                                >
                                    Tidak ada petugas pada filter ini.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="loading"
                    class="p-4 text-center text-sm text-muted-foreground"
                >
                    Memuat data proyeksi...
                </div>
                <div
                    class="flex flex-col gap-3 border-t border-sidebar-border/70 p-4 text-sm md:flex-row md:items-center md:justify-between dark:border-sidebar-border"
                >
                    <div class="text-muted-foreground">
                        Halaman {{ formatNumber(currentPage) }} dari
                        {{ formatNumber(totalPages) }}
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            class="rounded-md border border-input bg-background px-3 py-1.5 font-semibold transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
                            :disabled="currentPage <= 1"
                            @click="currentPage--"
                        >
                            Sebelumnya
                        </button>
                        <button
                            class="rounded-md border border-input bg-background px-3 py-1.5 font-semibold transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
                            :disabled="currentPage >= totalPages"
                            @click="currentPage++"
                        >
                            Berikutnya
                        </button>
                    </div>
                </div>
            </section>
        </section>

        <div
            v-if="selectedOfficerKey"
            class="fixed inset-0 z-50 flex items-end bg-background/75 p-0 backdrop-blur-sm md:items-center md:justify-center md:p-6"
            @click.self="closeDetail"
        >
            <div
                class="max-h-[92vh] w-full overflow-y-auto rounded-t-xl border border-sidebar-border/70 bg-card p-5 shadow-2xl md:max-w-5xl md:rounded-xl md:p-6 dark:border-sidebar-border"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p
                            class="text-xs font-semibold tracking-[0.14em] text-emerald-600 uppercase dark:text-emerald-300"
                        >
                            Tampilan rinci petugas
                        </p>
                        <h2 class="mt-1 text-2xl font-bold">
                            {{ detail?.officer?.name ?? 'Memuat...' }}
                        </h2>
                    </div>
                    <button
                        class="rounded-md border border-input bg-background p-2 transition hover:bg-muted"
                        aria-label="Tutup detail"
                        @click="closeDetail"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div
                    v-if="detailLoading"
                    class="py-10 text-center text-muted-foreground"
                >
                    Memuat detail petugas...
                </div>
                <div
                    v-else-if="detail?.empty"
                    class="py-10 text-center text-muted-foreground"
                >
                    {{ detail.message }}
                </div>
                <div v-else-if="detail?.metrics" class="mt-6 space-y-6">
                    <div class="grid gap-3 md:grid-cols-5">
                        <div
                            v-for="card in detailMetricCards"
                            :key="card.label"
                            class="rounded-xl border border-sidebar-border/70 bg-background/60 p-4 dark:border-sidebar-border"
                        >
                            <component
                                :is="card.icon"
                                class="size-4 text-emerald-600 dark:text-emerald-300"
                            />
                            <p class="mt-2 text-xs text-muted-foreground">
                                {{ card.label }}
                            </p>
                            <p class="text-2xl font-bold">
                                {{ formatNumber(card.value) }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="grid gap-3 rounded-xl border border-sidebar-border/70 bg-muted/35 p-4 md:grid-cols-4 dark:border-sidebar-border"
                    >
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Reject %
                            </p>
                            <p class="text-xl font-bold text-rose-600">
                                {{ formatRate(detail.metrics.rejection_rate) }}%
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Laju Efektif/Hari
                            </p>
                            <p class="text-xl font-bold">
                                {{
                                    formatRate(
                                        detail.metrics
                                            .quality_adjusted_daily_rate,
                                    )
                                }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Estimasi Efektif
                            </p>
                            <p class="text-xl font-bold">
                                {{
                                    formatDate(
                                        detail.metrics
                                            .quality_adjusted_finish_date,
                                    )
                                }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Risiko Reject
                            </p>
                            <span
                                class="mt-1 inline-flex rounded-full border px-2.5 py-1 text-xs font-bold"
                                :class="
                                    rejectRiskClass(detail.metrics.reject_risk)
                                "
                            >
                                {{ detail.metrics.reject_risk_label }}
                            </span>
                        </div>
                    </div>

                    <div class="grid gap-3 lg:grid-cols-[1.2fr_0.8fr]">
                        <div
                            class="rounded-xl border border-sidebar-border/70 bg-background/60 p-4 dark:border-sidebar-border"
                        >
                            <h3 class="text-sm font-semibold">
                                Target vs Realisasi
                            </h3>
                            <VueApexCharts
                                height="280"
                                type="line"
                                :options="detailTrendOptions"
                                :series="detailTrendSeries"
                            />
                        </div>
                        <div
                            class="rounded-xl border border-sidebar-border/70 bg-background/60 p-4 dark:border-sidebar-border"
                        >
                            <h3 class="text-sm font-semibold">
                                Breakdown Status
                            </h3>
                            <div
                                class="mt-3 max-h-72 space-y-2 overflow-y-auto"
                            >
                                <div
                                    v-for="status in statusColumns"
                                    :key="status"
                                    class="flex items-center justify-between rounded-md bg-muted/55 px-3 py-2 text-sm"
                                >
                                    <span>{{ status }}</span>
                                    <strong>
                                        {{
                                            formatNumber(
                                                detail.status_totals?.[
                                                    status
                                                ] ?? 0,
                                            )
                                        }}
                                    </strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        class="rounded-xl border border-sidebar-border/70 bg-background/60 p-4 dark:border-sidebar-border"
                    >
                        <h3 class="text-sm font-semibold">Wilayah Tugas</h3>
                        <div class="mt-3 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead
                                    class="text-xs text-muted-foreground uppercase"
                                >
                                    <tr>
                                        <th class="py-2 text-left">Sub-SLS</th>
                                        <th class="py-2 text-left">Desa</th>
                                        <th class="py-2 text-right">Total</th>
                                        <th class="py-2 text-right">Submit</th>
                                        <th class="py-2 text-right">Reject</th>
                                        <th class="py-2 text-right">Open</th>
                                        <th class="py-2 text-right">Draft</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="region in detail.regions ?? []"
                                        :key="region.idsubsls"
                                        class="border-t border-sidebar-border/60"
                                    >
                                        <td class="py-2 pr-3 font-semibold">
                                            {{ region.label }}
                                            <div
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{ region.idsubsls }}
                                            </div>
                                        </td>
                                        <td class="py-2 pr-3">
                                            {{ region.desa }}
                                        </td>
                                        <td class="py-2 text-right">
                                            {{
                                                formatNumber(
                                                    region.total_assignment,
                                                )
                                            }}
                                        </td>
                                        <td class="py-2 text-right">
                                            {{
                                                formatNumber(
                                                    region.submitted_total,
                                                )
                                            }}
                                        </td>
                                        <td
                                            class="py-2 text-right text-rose-600"
                                        >
                                            {{
                                                formatNumber(
                                                    region.rejected_total,
                                                )
                                            }}
                                        </td>
                                        <td class="py-2 text-right">
                                            {{
                                                formatNumber(region.open_total)
                                            }}
                                        </td>
                                        <td class="py-2 text-right">
                                            {{
                                                formatNumber(region.draft_total)
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</template>
