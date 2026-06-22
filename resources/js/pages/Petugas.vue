<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useDark } from '@vueuse/core';
import { ref, computed, watch, onMounted } from 'vue';
import VueApexCharts from 'vue3-apexcharts';

const props = defineProps<{
    db_ready: boolean;
    kec_options: { code: string; name: string }[];
}>();

// ── types ─────────────────────────────────────────────────────────────────
interface PetugasRow {
    uid: string;
    nama: string;
    email: string;
    total: number;
    draft: number;
    submitted: number;
    approved: number;
    rejected: number;
    rejection_rate: number;
    progress_pct: number;
}

interface TurnaroundPencacah {
    uid: string;
    nama: string;
    avg_minutes: number;
    sample_count: number;
}

interface TurnaroundPengawas {
    uid: string;
    nama: string;
    avg_minutes: number;
    sample_count: number;
    approved_count: number;
    rejected_count: number;
}

interface QualityRow {
    uid: string;
    nama: string;
    email: string;
    total: number;
    avg_error: number;
    avg_clean: number;
    avg_remark: number;
    error_count: number;
    error_pct: number;
}

interface GelombangRow {
    label: string;
    total_pencacah: number;
    total_rt: number;
    progress_pct: number;
    approved_pct: number;
    submitted: number;
    approved: number;
    rejected: number;
}

// ── state ─────────────────────────────────────────────────────────────────
const isDark = useDark();
const activeTab = ref<'pencacah' | 'turnaround' | 'quality' | 'gelombang'>('pencacah');
const kdkec = ref('');
const loading = ref(false);

const pencacahList = ref<PetugasRow[]>([]);
const sortKey = ref<keyof PetugasRow>('total');
const sortDir = ref<'asc' | 'desc'>('desc');

const turnaroundPencacah = ref<TurnaroundPencacah[]>([]);
const turnaroundPengawas = ref<TurnaroundPengawas[]>([]);
const turnaroundView = ref<'pencacah' | 'pengawas'>('pencacah');

const qualityList = ref<QualityRow[]>([]);
const qualitySortKey = ref<keyof QualityRow>('avg_error');

const gelombangList = ref<GelombangRow[]>([]);
const gelombangGroupBy = ref<'gelombang' | 'kelas' | 'tc'>('gelombang');

// ── computed ───────────────────────────────────────────────────────────────
const sortedPencacah = computed(() =>
    [...pencacahList.value].sort((a, b) => {
        const av = a[sortKey.value] as number;
        const bv = b[sortKey.value] as number;
        return sortDir.value === 'desc' ? bv - av : av - bv;
    }),
);

const sortedQuality = computed(() =>
    [...qualityList.value].sort(
        (a, b) => (b[qualitySortKey.value] as number) - (a[qualitySortKey.value] as number),
    ),
);

// ── fetch ─────────────────────────────────────────────────────────────────
async function fetchTab(tab: typeof activeTab.value) {
    if (!props.db_ready) return;
    loading.value = true;
    const kecParam = kdkec.value ? `kdkec=${kdkec.value}` : '';

    try {
        if (tab === 'pencacah') {
            const res = await fetch(`/api/petugas/list?${kecParam}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            pencacahList.value = await res.json();
        } else if (tab === 'turnaround') {
            const res = await fetch(`/api/petugas/turnaround?${kecParam}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const d = await res.json();
            turnaroundPencacah.value = d.pencacah ?? [];
            turnaroundPengawas.value = d.pengawas ?? [];
        } else if (tab === 'quality') {
            const res = await fetch(`/api/petugas/quality?${kecParam}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            qualityList.value = await res.json();
        } else if (tab === 'gelombang') {
            const res = await fetch(
                `/api/petugas/gelombang?group_by=${gelombangGroupBy.value}`,
                { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } },
            );
            gelombangList.value = await res.json();
        }
    } finally {
        loading.value = false;
    }
}

onMounted(() => fetchTab('pencacah'));
watch(activeTab, (tab) => fetchTab(tab));
watch(kdkec, () => fetchTab(activeTab.value));
watch(gelombangGroupBy, () => {
    if (activeTab.value === 'gelombang') fetchTab('gelombang');
});

function setSort(key: keyof PetugasRow) {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'desc' ? 'asc' : 'desc';
    } else {
        sortKey.value = key;
        sortDir.value = 'desc';
    }
}

// ── charts ─────────────────────────────────────────────────────────────────
const chartTextColor = computed(() => (isDark.value ? '#9ca3af' : '#6b7280'));

function fmtMinutes(m: number): string {
    if (m < 60) return `${Math.round(m)} mnt`;
    const h = Math.floor(m / 60);
    const r = Math.round(m % 60);
    return r > 0 ? `${h}j ${r}m` : `${h}j`;
}

const turnaroundChartData = computed(() =>
    turnaroundView.value === 'pencacah'
        ? turnaroundPencacah.value
        : turnaroundPengawas.value,
);

const turnaroundChartOptions = computed(() => ({
    chart: {
        type: 'bar' as const,
        background: 'transparent',
        toolbar: { show: false },
        animations: { enabled: false },
    },
    theme: { mode: (isDark.value ? 'dark' : 'light') as 'dark' | 'light' },
    plotOptions: { bar: { horizontal: true, borderRadius: 3 } },
    dataLabels: {
        enabled: true,
        formatter: (v: number) => fmtMinutes(v),
        style: { fontSize: '10px' },
    },
    xaxis: {
        categories: turnaroundChartData.value.slice(0, 20).map((r) => r.nama ?? r.uid),
        labels: { style: { colors: chartTextColor.value, fontSize: '11px' } },
        title: { text: 'Rata-rata (menit)', style: { color: chartTextColor.value } },
    },
    yaxis: {
        labels: {
            style: { colors: chartTextColor.value, fontSize: '11px' },
            maxWidth: 160,
        },
    },
    tooltip: { y: { formatter: (v: number) => fmtMinutes(v) } },
    colors: ['#6366f1'],
    grid: { borderColor: isDark.value ? '#374151' : '#e5e7eb' },
}));

const turnaroundSeries = computed(() => [
    {
        name: 'Rata-rata waktu (menit)',
        data: turnaroundChartData.value.slice(0, 20).map((r) => r.avg_minutes),
    },
]);

const gelombangChartOptions = computed(() => ({
    chart: {
        type: 'bar' as const,
        background: 'transparent',
        toolbar: { show: false },
        animations: { enabled: false },
    },
    theme: { mode: (isDark.value ? 'dark' : 'light') as 'dark' | 'light' },
    plotOptions: { bar: { borderRadius: 3, columnWidth: '60%' } },
    dataLabels: { enabled: false },
    xaxis: {
        categories: gelombangList.value.map((r) => r.label),
        labels: { style: { colors: chartTextColor.value, fontSize: '11px' } },
    },
    yaxis: {
        max: 100,
        labels: {
            formatter: (v: number) => `${v}%`,
            style: { colors: chartTextColor.value },
        },
    },
    tooltip: { y: { formatter: (v: number) => `${v}%` } },
    colors: ['#10b981', '#6366f1'],
    grid: { borderColor: isDark.value ? '#374151' : '#e5e7eb' },
    legend: { labels: { colors: chartTextColor.value } },
}));

const gelombangSeries = computed(() => [
    { name: 'Progress %', data: gelombangList.value.map((r) => r.progress_pct) },
    { name: 'Approved %', data: gelombangList.value.map((r) => r.approved_pct) },
]);
</script>

<template>
    <Head title="Analitik Petugas" />

    <div
        v-if="!db_ready"
        class="flex h-64 items-center justify-center text-sm text-muted-foreground"
    >
        Import database FASIH terlebih dahulu.
    </div>

    <div v-else class="space-y-4 p-4">
        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Analitik Petugas</h1>
                <p class="text-xs text-muted-foreground">
                    Performa pencacah & pengawas berdasarkan data penugasan terkini
                </p>
            </div>
            <select
                v-model="kdkec"
                class="h-8 rounded-md border border-input bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-ring"
            >
                <option value="">Semua Kecamatan</option>
                <option v-for="k in kec_options" :key="k.code" :value="k.code">
                    {{ k.name }}
                </option>
            </select>
        </div>

        <!-- Tabs -->
        <div class="flex gap-1 rounded-lg border bg-muted/40 p-1">
            <button
                v-for="tab in [
                    { id: 'pencacah', label: 'Pencacah' },
                    { id: 'turnaround', label: 'Kecepatan Proses' },
                    { id: 'quality', label: 'Quality Control' },
                    { id: 'gelombang', label: 'Gelombang / TC' },
                ]"
                :key="tab.id"
                :class="[
                    'flex-1 rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                    activeTab === tab.id
                        ? 'bg-background text-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground',
                ]"
                @click="activeTab = tab.id as typeof activeTab"
            >
                {{ tab.label }}
            </button>
        </div>

        <div
            v-if="loading"
            class="flex h-48 items-center justify-center text-sm text-muted-foreground"
        >
            Memuat data...
        </div>

        <!-- ── Tab: Pencacah ──────────────────────────────────────── -->
        <div v-else-if="activeTab === 'pencacah'">
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-xs">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">#</th>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">Nama</th>
                            <th
                                v-for="col in [
                                    { key: 'total', label: 'Total' },
                                    { key: 'draft', label: 'Draft' },
                                    { key: 'submitted', label: 'Submit' },
                                    { key: 'approved', label: 'Approved' },
                                    { key: 'rejected', label: 'Rejected' },
                                    { key: 'rejection_rate', label: 'Reject %' },
                                ]"
                                :key="col.key"
                                class="cursor-pointer px-3 py-2 text-right font-medium text-muted-foreground hover:text-foreground"
                                @click="setSort(col.key as keyof PetugasRow)"
                            >
                                {{ col.label }}
                                {{ sortKey === col.key ? (sortDir === 'desc' ? '↓' : '↑') : '' }}
                            </th>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">Progress</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="(row, i) in sortedPencacah"
                            :key="row.uid"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-3 py-2 text-muted-foreground">{{ i + 1 }}</td>
                            <td class="px-3 py-2">
                                <div class="font-medium">{{ row.nama }}</div>
                                <div class="text-[10px] text-muted-foreground">{{ row.email }}</div>
                            </td>
                            <td class="px-3 py-2 text-right font-medium">{{ row.total.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right text-muted-foreground">{{ row.draft.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right text-blue-600 dark:text-blue-400">{{ row.submitted.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right text-emerald-600 dark:text-emerald-400">{{ row.approved.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right text-red-600 dark:text-red-400">{{ row.rejected.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right">
                                <span
                                    :class="[
                                        'rounded px-1.5 py-0.5 text-xs font-medium',
                                        row.rejection_rate > 20
                                            ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'
                                            : row.rejection_rate > 10
                                              ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'
                                              : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
                                    ]"
                                >{{ row.rejection_rate }}%</span>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 w-20 overflow-hidden rounded-full bg-muted">
                                        <div
                                            class="h-full rounded-full bg-emerald-500 transition-all"
                                            :style="{ width: `${Math.min(100, row.progress_pct)}%` }"
                                        />
                                    </div>
                                    <span class="text-muted-foreground">{{ row.progress_pct }}%</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!pencacahList.length" class="py-8 text-center text-sm text-muted-foreground">
                    Tidak ada data
                </div>
            </div>
        </div>

        <!-- ── Tab: Turnaround ────────────────────────────────────── -->
        <div v-else-if="activeTab === 'turnaround'" class="space-y-4">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="v in [
                        { id: 'pencacah', label: 'Pencacah — DRAFT → Submit' },
                        { id: 'pengawas', label: 'Pengawas — Submit → Review' },
                    ]"
                    :key="v.id"
                    :class="[
                        'rounded-md border px-3 py-1.5 text-xs font-medium transition-colors',
                        turnaroundView === v.id
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-input bg-background text-muted-foreground hover:text-foreground',
                    ]"
                    @click="turnaroundView = v.id as typeof turnaroundView"
                >
                    {{ v.label }}
                </button>
            </div>

            <div
                v-if="turnaroundChartData.length === 0"
                class="flex h-48 items-center justify-center rounded-lg border text-sm text-muted-foreground"
            >
                Tidak ada data (pencacah perlu ≥ 3 submit)
            </div>

            <div v-else class="rounded-lg border p-3">
                <p class="mb-3 text-xs text-muted-foreground">
                    {{
                        turnaroundView === 'pencacah'
                            ? 'Rata-rata menit dari DRAFT terakhir ke SUBMIT. Tampil 20 tercepat.'
                            : 'Rata-rata menit dari SUBMIT ke APPROVE/REJECT per pengawas. Tampil 20 tercepat.'
                    }}
                </p>
                <VueApexCharts
                    type="bar"
                    :height="Math.max(200, turnaroundChartData.slice(0, 20).length * 28)"
                    :options="turnaroundChartOptions"
                    :series="turnaroundSeries"
                />
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
                        <template v-if="turnaroundView === 'pencacah'">
                            <tr
                                v-for="r in turnaroundPencacah"
                                :key="r.uid"
                                class="hover:bg-muted/30"
                            >
                                <td class="px-3 py-2">{{ r.nama ?? r.uid }}</td>
                                <td class="px-3 py-2 text-right font-medium">{{ fmtMinutes(r.avg_minutes) }}</td>
                                <td class="px-3 py-2 text-right text-muted-foreground">{{ r.sample_count }}</td>
                            </tr>
                        </template>
                        <template v-else>
                            <tr
                                v-for="r in turnaroundPengawas"
                                :key="r.uid"
                                class="hover:bg-muted/30"
                            >
                                <td class="px-3 py-2">{{ r.nama ?? r.uid }}</td>
                                <td class="px-3 py-2 text-right font-medium">{{ fmtMinutes(r.avg_minutes) }}</td>
                                <td class="px-3 py-2 text-right text-muted-foreground">{{ r.sample_count }}</td>
                                <td class="px-3 py-2 text-right text-emerald-600 dark:text-emerald-400">{{ r.approved_count }}</td>
                                <td class="px-3 py-2 text-right text-red-600 dark:text-red-400">{{ r.rejected_count }}</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Tab: Quality ───────────────────────────────────────── -->
        <div v-else-if="activeTab === 'quality'">
            <p class="mb-3 text-xs text-muted-foreground">
                Metrik kualitas isian dari kolom
                <code class="rounded bg-muted px-1">sum_error</code>,
                <code class="rounded bg-muted px-1">sum_clean</code>,
                <code class="rounded bg-muted px-1">sum_remark</code> per penugasan.
            </p>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-xs">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">#</th>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">Nama</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Total</th>
                            <th
                                class="cursor-pointer px-3 py-2 text-right font-medium text-muted-foreground hover:text-foreground"
                                @click="qualitySortKey = 'avg_error'"
                            >Avg Error {{ qualitySortKey === 'avg_error' ? '↓' : '' }}</th>
                            <th
                                class="cursor-pointer px-3 py-2 text-right font-medium text-muted-foreground hover:text-foreground"
                                @click="qualitySortKey = 'avg_clean'"
                            >Avg Clean {{ qualitySortKey === 'avg_clean' ? '↓' : '' }}</th>
                            <th
                                class="cursor-pointer px-3 py-2 text-right font-medium text-muted-foreground hover:text-foreground"
                                @click="qualitySortKey = 'error_pct'"
                            >% Bermasalah {{ qualitySortKey === 'error_pct' ? '↓' : '' }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="(row, i) in sortedQuality"
                            :key="row.uid"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-3 py-2 text-muted-foreground">{{ i + 1 }}</td>
                            <td class="px-3 py-2">
                                <div class="font-medium">{{ row.nama }}</div>
                                <div class="text-[10px] text-muted-foreground">{{ row.email }}</div>
                            </td>
                            <td class="px-3 py-2 text-right">{{ row.total.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right">
                                <span :class="row.avg_error > 1 ? 'font-medium text-red-600 dark:text-red-400' : ''">
                                    {{ row.avg_error.toFixed(2) }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right text-emerald-600 dark:text-emerald-400">
                                {{ row.avg_clean.toFixed(2) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <span
                                    :class="[
                                        'rounded px-1.5 py-0.5 font-medium',
                                        row.error_pct > 30
                                            ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'
                                            : row.error_pct > 10
                                              ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'
                                              : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
                                    ]"
                                >{{ row.error_pct }}%</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!qualityList.length" class="py-8 text-center text-sm text-muted-foreground">
                    Tidak ada data
                </div>
            </div>
        </div>

        <!-- ── Tab: Gelombang / TC ────────────────────────────────── -->
        <div v-else-if="activeTab === 'gelombang'" class="space-y-4">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="g in [
                        { id: 'gelombang', label: 'Gelombang' },
                        { id: 'kelas', label: 'Kelas' },
                        { id: 'tc', label: 'Training Center' },
                    ]"
                    :key="g.id"
                    :class="[
                        'rounded-md border px-3 py-1.5 text-xs font-medium transition-colors',
                        gelombangGroupBy === g.id
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-input bg-background text-muted-foreground hover:text-foreground',
                    ]"
                    @click="gelombangGroupBy = g.id as typeof gelombangGroupBy"
                >
                    {{ g.label }}
                </button>
            </div>

            <div v-if="gelombangList.length" class="rounded-lg border p-3">
                <VueApexCharts
                    type="bar"
                    :height="260"
                    :options="gelombangChartOptions"
                    :series="gelombangSeries"
                />
            </div>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-xs">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-muted-foreground">
                                {{
                                    gelombangGroupBy === 'gelombang'
                                        ? 'Gelombang'
                                        : gelombangGroupBy === 'kelas'
                                          ? 'Kelas'
                                          : 'Training Center'
                                }}
                            </th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Pencacah</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Total RT</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Submitted</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Approved</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Rejected</th>
                            <th class="px-3 py-2 text-right font-medium text-muted-foreground">Progress</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="row in gelombangList"
                            :key="row.label"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-3 py-2 font-medium">{{ row.label }}</td>
                            <td class="px-3 py-2 text-right">{{ row.total_pencacah }}</td>
                            <td class="px-3 py-2 text-right">{{ row.total_rt.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right text-blue-600 dark:text-blue-400">{{ row.submitted.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right text-emerald-600 dark:text-emerald-400">{{ row.approved.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right text-red-600 dark:text-red-400">{{ row.rejected.toLocaleString('id') }}</td>
                            <td class="px-3 py-2 text-right font-medium">{{ row.progress_pct }}%</td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!gelombangList.length" class="py-8 text-center text-sm text-muted-foreground">
                    Tidak ada data
                </div>
            </div>
        </div>
    </div>
</template>
