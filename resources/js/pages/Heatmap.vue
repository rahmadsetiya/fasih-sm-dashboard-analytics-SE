<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Sun, Moon, X } from '@lucide/vue';
import { useDark } from '@vueuse/core';
import { ref, computed, watch, onMounted, reactive } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import AppShell from '@/components/AppShell.vue';

// ── props ──────────────────────────────────────────────────────────────────
interface WilayahItem {
    uuid: string;
    code: string;
    name: string;
    parent_uuid: string;
}

const props = defineProps<{
    db_ready: boolean;
    date_range: { min: string; max: string } | null;
    wilayah: Record<string, WilayahItem[]>;
}>();

// ── types ──────────────────────────────────────────────────────────────────
interface HeatmapSeries {
    name: string;
    data: { x: string; y: number }[];
    total: number;
}

type Dimension = 'pencacah' | 'pengawas';

const STATUS_OPTIONS = [
    { id: 1 as const, label: 'Submitted By Pencacah', shortLabel: 'Submitted', color: '#f97316' },
    { id: 2 as const, label: 'Approved By Pengawas', shortLabel: 'Approved', color: '#22c55e' },
    { id: 3 as const, label: 'Rejected By Pengawas', shortLabel: 'Rejected', color: '#ef4444' },
];

function colorWithOpacity(hex: string, opacity: number): string {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);

    return `rgba(${r}, ${g}, ${b}, ${opacity})`;
}

// ── state ──────────────────────────────────────────────────────────────────
const isDark = useDark();
const dimension = ref<Dimension>('pencacah');
const MAX_RANGE_DAYS = 14;

function addDays(dateStr: string, days: number): string {
    const d = new Date(dateStr);
    d.setDate(d.getDate() + days);

    return d.toISOString().slice(0, 10);
}

const dateFrom = ref('2026-06-15');
const dateTo = ref((() => {
    const raw = props.date_range?.max ?? '';

    if (!raw) {
      return '';
    }

    const cap = addDays('2026-06-15', MAX_RANGE_DAYS);

    return raw > cap ? cap : raw;
})());

// Wilayah cascade selection (store UUIDs)
const selectedKecUuid = ref('');
const selectedDesaUuid = ref('');
const selectedSlsUuid = ref('');
const selectedSubslsUuid = ref('');

// Chart data per status id
const seriesData = reactive<Record<number, HeatmapSeries[]>>({ 1: [], 2: [], 3: [] });
const panelLoading = reactive<Record<number, boolean>>({ 1: false, 2: false, 3: false });

// ── wilayah cascade computed ──────────────────────────────────────────────
const kecList = computed<WilayahItem[]>(() => props.wilayah['3'] ?? []);

const selectedKec = computed(() => kecList.value.find((k) => k.uuid === selectedKecUuid.value) ?? null);

const desaList = computed<WilayahItem[]>(() => {
    if (!selectedKec.value) {
return [];
}

    return (props.wilayah['4'] ?? []).filter((d) => d.parent_uuid === selectedKec.value!.uuid);
});

const selectedDesa = computed(() => desaList.value.find((d) => d.uuid === selectedDesaUuid.value) ?? null);

const slsList = computed<WilayahItem[]>(() => {
    if (!selectedDesa.value) {
return [];
}

    return (props.wilayah['5'] ?? []).filter((s) => s.parent_uuid === selectedDesa.value!.uuid);
});

const selectedSls = computed(() => slsList.value.find((s) => s.uuid === selectedSlsUuid.value) ?? null);

const subslsList = computed<WilayahItem[]>(() => {
    if (!selectedSls.value) {
return [];
}

    return (props.wilayah['6'] ?? []).filter((ss) => ss.parent_uuid === selectedSls.value!.uuid);
});

const selectedSubsls = computed(() =>
    subslsList.value.find((ss) => ss.uuid === selectedSubslsUuid.value) ?? null,
);

// Active filter for the API (most specific selected level wins)
const activeFilter = computed<{ level: string; code: string } | null>(() => {
    if (selectedSubsls.value) {
return { level: 'subsls', code: selectedSubsls.value.code };
}

    if (selectedSls.value) {
return { level: 'sls', code: selectedSls.value.code };
}

    if (selectedDesa.value) {
return { level: 'desa', code: selectedDesa.value.code };
}

    if (selectedKec.value) {
return { level: 'kec', code: selectedKec.value.code };
}

    return null;
});

// Breadcrumb label for current geo selection
const geoBreadcrumb = computed(() => {
    const parts: string[] = [];

    if (selectedKec.value) {
parts.push(selectedKec.value.name);
}

    if (selectedDesa.value) {
parts.push(selectedDesa.value.name);
}

    if (selectedSls.value) {
parts.push(selectedSls.value.name);
}

    if (selectedSubsls.value) {
parts.push(selectedSubsls.value.name);
}

    return parts.join(' › ');
});

// ── cascade watchers ──────────────────────────────────────────────────────
watch(selectedKecUuid, () => {
    selectedDesaUuid.value = '';
    selectedSlsUuid.value = '';
    selectedSubslsUuid.value = '';
});
watch(selectedDesaUuid, () => {
    selectedSlsUuid.value = '';
    selectedSubslsUuid.value = '';
});
watch(selectedSlsUuid, () => {
    selectedSubslsUuid.value = '';
});

watch(dateFrom, (val) => {
    if (!val || !dateTo.value) {
return;
}

    if (dateTo.value > addDays(val, MAX_RANGE_DAYS)) {
dateTo.value = addDays(val, MAX_RANGE_DAYS);
}

    if (dateTo.value < val) {
dateTo.value = val;
}
});

watch(dateTo, (val) => {
    if (!val || !dateFrom.value) {
return;
}

    const minFrom = addDays(val, -MAX_RANGE_DAYS);

    if (dateFrom.value < minFrom) {
dateFrom.value = minFrom;
}

    if (dateFrom.value > val) {
dateFrom.value = val;
}
});

// ── chart options factory ─────────────────────────────────────────────────
function makeChartOptions(color: string) {
    const textColor = isDark.value ? '#a1a1aa' : '#71717a';
    const zeroColor = isDark.value ? '#27272a' : '#f4f4f5';

    return {
        chart: {
            type: 'heatmap' as const,
            toolbar: { show: false },
            background: 'transparent',
            fontFamily: 'inherit',
            animations: { enabled: false },
        },
        dataLabels: { enabled: false },
        plotOptions: {
            heatmap: {
                enableShades: false,
                radius: 2,
                colorScale: {
                    ranges: [
                        { from: 0, to: 0, color: zeroColor, name: '0' },
                        { from: 1, to: 3, color: colorWithOpacity(color, 0.3), name: '1–3' },
                        { from: 4, to: 10, color: colorWithOpacity(color, 0.65), name: '4–10' },
                        { from: 11, to: 99999, color: color, name: '11+' },
                    ],
                },
            },
        },
        xaxis: {
            labels: {
                rotate: -45,
                style: { fontSize: '10px', colors: textColor },
                formatter: (val: string) => val.slice(5),
            },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: {
            labels: { style: { fontSize: '11px', colors: textColor }, maxWidth: 120 },
        },
        grid: {
            borderColor: isDark.value ? '#3f3f46' : '#e4e4e7',
            xaxis: { lines: { show: false } },
            yaxis: { lines: { show: false } },
        },
        tooltip: {
            theme: isDark.value ? ('dark' as const) : ('light' as const),
            y: {
                formatter: (val: number) => `${val} perubahan`,
                title: { formatter: () => '' },
            },
            x: { show: true },
        },
        legend: { show: false },
        theme: { mode: isDark.value ? ('dark' as const) : ('light' as const) },
    };
}

const chartOptionsMap = computed(() => ({
    1: makeChartOptions('#f97316'),
    2: makeChartOptions('#22c55e'),
    3: makeChartOptions('#ef4444'),
}));

function chartHeight(statusId: number): number {
    const rows = seriesData[statusId]?.length ?? 0;

    return Math.max(160, rows * 28 + 80);
}

// ── fetch ─────────────────────────────────────────────────────────────────
async function fetchStatus(statusId: 1 | 2 | 3) {
    panelLoading[statusId] = true;

    try {
        const params = new URLSearchParams({
            status_id: String(statusId),
            dimension: dimension.value,
        });

        if (dateFrom.value) {
params.set('date_from', dateFrom.value);
}

        if (dateTo.value) {
params.set('date_to', dateTo.value);
}

        const af = activeFilter.value;

        if (af) {
            params.set('filter_level', af.level);
            params.set('filter_code', af.code);
        }

        const res = await fetch(`/api/heatmap?${params}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json();
        seriesData[statusId] = data.series ?? [];
    } finally {
        panelLoading[statusId] = false;
    }
}

function fetchAll() {
    if (!props.db_ready) {
return;
}

    fetchStatus(1);
    fetchStatus(2);
    fetchStatus(3);
}

watch([dimension, dateFrom, dateTo, activeFilter], fetchAll);
onMounted(fetchAll);

// ── reset geo ─────────────────────────────────────────────────────────────
function resetGeo() {
    selectedKecUuid.value = '';
}
</script>

<template>
    <Head title="Heatmap Aktivitas" />

    <AppShell>
        <div class="flex flex-col gap-5 p-4 md:p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-zinc-900 dark:text-zinc-100">Heatmap Aktivitas</h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Perubahan status per petugas per hari
                        <span v-if="db_ready && date_range">
                            — {{ date_range.min }} s.d. {{ date_range.max }}
                        </span>
                        <span v-else-if="!db_ready" class="text-amber-500">— Database belum diupload</span>
                    </p>
                </div>
                <button
                    class="rounded-md p-2 text-zinc-500 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                    :aria-label="isDark ? 'Mode terang' : 'Mode gelap'"
                    @click="isDark = !isDark"
                >
                    <Sun v-if="isDark" class="size-4" />
                    <Moon v-else class="size-4" />
                </button>
            </div>

            <!-- No DB -->
            <div
                v-if="!db_ready"
                class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-300"
            >
                Upload file <code>fasih.db</code> terlebih dahulu via tombol di sidebar.
            </div>

            <template v-else>
                <!-- ── Filter bar ─────────────────────────────────────────── -->
                <div
                    class="space-y-3 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900"
                >
                    <!-- Row 1: date + dimension -->
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="flex flex-col gap-1">
                            <label class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Dari</label>
                            <input
                                v-model="dateFrom"
                                type="date"
                                class="h-8 rounded-md border border-zinc-300 bg-white px-2 text-sm text-zinc-800 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Sampai</label>
                            <input
                                v-model="dateTo"
                                type="date"
                                class="h-8 rounded-md border border-zinc-300 bg-white px-2 text-sm text-zinc-800 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                        </div>

                        <!-- Dimension toggle -->
                        <div class="flex flex-col gap-1">
                            <label class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Tampilkan</label>
                            <div
                                class="flex rounded-md border border-zinc-300 bg-white text-sm dark:border-zinc-600 dark:bg-zinc-800"
                            >
                                <button
                                    class="rounded-l-md px-3 py-1.5 font-medium transition-colors"
                                    :class="
                                        dimension === 'pencacah'
                                            ? 'bg-orange-500 text-white'
                                            : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700'
                                    "
                                    @click="dimension = 'pencacah'"
                                >
                                    Per Pencacah
                                </button>
                                <button
                                    class="rounded-r-md px-3 py-1.5 font-medium transition-colors"
                                    :class="
                                        dimension === 'pengawas'
                                            ? 'bg-orange-500 text-white'
                                            : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700'
                                    "
                                    @click="dimension = 'pengawas'"
                                >
                                    Per Pengawas
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Row 2: Geo cascade -->
                    <div class="flex flex-wrap items-end gap-2">
                        <span class="self-center text-xs font-medium text-zinc-400 dark:text-zinc-500">
                            Filter Wilayah:
                        </span>

                        <!-- Kecamatan -->
                        <div class="flex flex-col gap-1">
                            <label class="text-xs text-zinc-400 dark:text-zinc-500">Kecamatan</label>
                            <select
                                v-model="selectedKecUuid"
                                class="h-8 min-w-[140px] rounded-md border border-zinc-300 bg-white px-2 text-sm text-zinc-800 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                            >
                                <option value="">— Semua —</option>
                                <option v-for="k in kecList" :key="k.uuid" :value="k.uuid">
                                    {{ k.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Desa — only when kec selected -->
                        <template v-if="selectedKec">
                            <span class="self-center text-zinc-400">›</span>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-zinc-400 dark:text-zinc-500">Desa/Kel</label>
                                <select
                                    v-model="selectedDesaUuid"
                                    class="h-8 min-w-[160px] rounded-md border border-zinc-300 bg-white px-2 text-sm text-zinc-800 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                                >
                                    <option value="">— Semua —</option>
                                    <option v-for="d in desaList" :key="d.uuid" :value="d.uuid">
                                        {{ d.name }}
                                    </option>
                                </select>
                            </div>
                        </template>

                        <!-- SLS — only when desa selected -->
                        <template v-if="selectedDesa">
                            <span class="self-center text-zinc-400">›</span>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-zinc-400 dark:text-zinc-500">SLS</label>
                                <select
                                    v-model="selectedSlsUuid"
                                    class="h-8 min-w-[160px] rounded-md border border-zinc-300 bg-white px-2 text-sm text-zinc-800 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                                >
                                    <option value="">— Semua —</option>
                                    <option v-for="s in slsList" :key="s.uuid" :value="s.uuid">
                                        {{ s.name }}
                                    </option>
                                </select>
                            </div>
                        </template>

                        <!-- Sub-SLS — only when sls selected -->
                        <template v-if="selectedSls">
                            <span class="self-center text-zinc-400">›</span>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-zinc-400 dark:text-zinc-500">Sub-SLS</label>
                                <select
                                    v-model="selectedSubslsUuid"
                                    class="h-8 min-w-[160px] rounded-md border border-zinc-300 bg-white px-2 text-sm text-zinc-800 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                                >
                                    <option value="">— Semua —</option>
                                    <option v-for="ss in subslsList" :key="ss.uuid" :value="ss.uuid">
                                        {{ ss.name }}
                                    </option>
                                </select>
                            </div>
                        </template>

                        <!-- Reset button -->
                        <button
                            v-if="selectedKec"
                            class="flex h-8 items-center gap-1 self-end rounded-md border border-zinc-300 px-2 text-xs text-zinc-500 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-700"
                            @click="resetGeo"
                        >
                            <X class="size-3" />
                            Reset
                        </button>
                    </div>

                    <!-- Active filter breadcrumb -->
                    <div
                        v-if="geoBreadcrumb"
                        class="text-xs text-orange-600 dark:text-orange-400"
                    >
                        Filter aktif: {{ geoBreadcrumb }}
                        <span class="ml-1 text-zinc-400">({{ activeFilter?.level }})</span>
                    </div>
                </div>

                <!-- ── 3 panels — stacked on mobile, side-by-side on lg+ ──── -->
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div
                    v-for="s in STATUS_OPTIONS"
                    :key="s.id"
                    class="rounded-lg border border-zinc-200 bg-white shadow-md dark:border-zinc-700 dark:bg-zinc-900"
                >
                    <!-- Panel header -->
                    <div
                        class="flex items-center gap-2 border-b border-zinc-100 px-4 py-2.5 dark:border-zinc-700"
                    >
                        <span
                            class="inline-block size-2.5 rounded-full"
                            :style="{ backgroundColor: s.color }"
                        />
                        <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">
                            {{ s.label }}
                        </span>
                        <span
                            v-if="!panelLoading[s.id] && seriesData[s.id].length"
                            class="ml-auto text-xs text-zinc-400 dark:text-zinc-500"
                        >
                            {{ seriesData[s.id].reduce((sum, r) => sum + r.total, 0).toLocaleString('id-ID') }}
                            perubahan
                            <template v-if="seriesData[s.id].length === 30">
                                &middot; top 30
                            </template>
                        </span>
                    </div>

                    <!-- Legend -->
                    <div class="flex items-center gap-3 border-b border-zinc-100 px-4 py-1.5 dark:border-zinc-700">
                        <span class="text-[10px] text-zinc-400 dark:text-zinc-500">Jumlah:</span>
                        <div class="flex items-center gap-2">
                            <span class="flex items-center gap-1">
                                <span class="inline-block size-3 rounded-sm" :style="{ backgroundColor: isDark ? '#27272a' : '#f4f4f5' }" />
                                <span class="text-[10px] text-zinc-400 dark:text-zinc-500">0</span>
                            </span>
                            <span class="flex items-center gap-1">
                                <span class="inline-block size-3 rounded-sm" :style="{ backgroundColor: colorWithOpacity(s.color, 0.3) }" />
                                <span class="text-[10px] text-zinc-400 dark:text-zinc-500">1–3</span>
                            </span>
                            <span class="flex items-center gap-1">
                                <span class="inline-block size-3 rounded-sm" :style="{ backgroundColor: colorWithOpacity(s.color, 0.65) }" />
                                <span class="text-[10px] text-zinc-400 dark:text-zinc-500">4–10</span>
                            </span>
                            <span class="flex items-center gap-1">
                                <span class="inline-block size-3 rounded-sm" :style="{ backgroundColor: s.color }" />
                                <span class="text-[10px] text-zinc-400 dark:text-zinc-500">11+</span>
                            </span>
                        </div>
                    </div>

                    <!-- Loading -->
                    <div
                        v-if="panelLoading[s.id]"
                        class="flex items-center justify-center py-12 text-sm text-zinc-400"
                    >
                        Memuat…
                    </div>

                    <!-- Empty -->
                    <div
                        v-else-if="!seriesData[s.id].length"
                        class="flex items-center justify-center py-12 text-sm text-zinc-400"
                    >
                        Tidak ada data.
                    </div>

                    <!-- Chart -->
                    <div v-else class="p-2">
                        <VueApexCharts
                            type="heatmap"
                            :options="chartOptionsMap[s.id]"
                            :series="seriesData[s.id]"
                            :height="chartHeight(s.id)"
                            width="100%"
                        />
                    </div>
                </div>
                </div><!-- end grid -->
            </template>
        </div>
    </AppShell>
</template>
